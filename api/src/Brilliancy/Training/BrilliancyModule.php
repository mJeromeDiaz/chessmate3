<?php

declare(strict_types=1);

namespace App\Brilliancy\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Brilliancy\BrilliancyRules;
use App\Brilliancy\Selection\PositionPicker;
use App\Chess\Rules;
use App\Entity\Brilliancy\Attempt;
use App\Entity\Catalog\Puzzle;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Brilliancy\AttemptStatus;
use App\Enum\Brilliancy\Kind;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Rating\RatingCalculator;
use App\Repository\Brilliancy\AttemptRepository;
use App\Repository\Puzzle\RatingRepository;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Module\BudgetChoicesInterface;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;
use App\Training\Module\ItemSubmission;
use App\Training\Module\PreparedStep;
use App\Training\Module\ReviewableModuleInterface;
use App\Training\Module\ReviewItem;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use Doctrine\ORM\EntityManagerInterface;
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * « Brillant ou Blunder » in timed runs (docs/BRILLIANCY.md): a position and a move that gives
 * material; the player says whether it is brilliant (the puzzle's winning sacrifice) or a blunder
 * (the opponent's move the puzzle punishes). The subject is the user, no option; a run lasts one of
 * {@see BrilliancyRules::RUN_MINUTES} and positions follow one another until its time is up. Half
 * of them of each kind, drawn at random around the player's puzzle rating; never rated. Nothing of
 * the answer before the verdict; the position on screen at the end is not counted.
 */
final class BrilliancyModule implements TimeboxedModuleInterface, ReviewableModuleInterface, BudgetChoicesInterface
{
    public const SUBJECT_TYPE = 'brilliancy_player';
    public const ITEM_TYPE = 'brilliancy_position';
    public const SOURCE_TYPE = 'brilliancy_attempt';

    private readonly Randomizer $randomizer;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AttemptRepository $attempts,
        private readonly PositionPicker $picker,
        private readonly PuzzleCatalog $catalog,
        private readonly RatingRepository $ratings,
        private readonly EventPublisher $events,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    public function module(): Module
    {
        return Module::Brilliancy;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function allowedBudgetSeconds(): array
    {
        return BrilliancyRules::budgets();
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        return new PreparedStep($user->getId(), self::check($settings));
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        if (!BrilliancyRules::allows($run->getBudgetSeconds())) {
            throw new InvalidRunConfigException(\sprintf('A run lasts %s minutes.', implode(', ', BrilliancyRules::RUN_MINUTES)));
        }
        self::check($run->getConfig());
        // Served now: an empty index refuses the run instead of opening an empty one.
        $this->serve($run, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        // A reload gives the same position back.
        return $this->item($this->attempts->findPendingOfRun($run) ?? $this->serve($run, $now));
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        $attempt = Uuid::isValid($submission->itemId) ? $this->attempts->findOwned(Uuid::fromString($submission->itemId), $run->getUser()) : null;
        if (null === $attempt || !$attempt->getRun()->getId()->equals($run->getId())) {
            throw new ItemNotFoundException();
        }
        if (!$attempt->isPending()) {
            throw new ItemAlreadySubmittedException();
        }
        $answer = null === $submission->choice ? null : Kind::tryFrom($submission->choice);
        if (null === $answer) {
            throw new InvalidItemSubmissionException(\sprintf('choice must be one of %s.', implode(', ', Kind::values())));
        }
        // On time: the runner refuses an answer after the run's end (its network tolerance aside).
        $attempt->resolve($answer, $now);
        $this->entityManager->flush();
        $this->log($run, $attempt, $now);

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: AttemptStatus::Right === $attempt->getStatus(),
            data: [
                ...$this->correction($attempt, $this->catalog->find($attempt->getPuzzleId())),
                ...self::tally($this->attempts->countsOfRun($run)),
            ],
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The position on screen at the end is not counted.
        $pending = $this->attempts->findPendingOfRun($run);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
        }
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $counts = $this->attempts->countsOfRun($run);
        $activeMs = 0;
        foreach ($this->attempts->findResolvedOfRun($run) as $attempt) {
            $activeMs += $attempt->getDurationMs() ?? 0;
        }

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $counts['played'],
            successCount: $counts['right'],
            metrics: [
                'right' => $counts['right'],
                'wrong' => $counts['wrong'],
                'brilliant' => $counts['brilliant'],
                'blunder' => $counts['blunder'],
                'activeMs' => $activeMs,
                'averageMs' => $counts['played'] > 0 ? intdiv($activeMs, $counts['played']) : null,
            ],
        );
    }

    public function review(Run $run): array
    {
        $attempts = $this->attempts->findResolvedOfRun($run);
        $puzzles = $this->catalog->byIds(array_map(static fn (Attempt $attempt): int => $attempt->getPuzzleId(), $attempts));

        return array_map(fn (Attempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            AttemptStatus::Right === $attempt->getStatus() ? ReviewItem::OK : ReviewItem::FAIL,
            $attempt->getDurationMs(),
            [
                'position' => self::position($attempt),
                ...$this->correction($attempt, $puzzles[$attempt->getPuzzleId()] ?? null),
            ],
        ), $attempts);
    }

    /**
     * A new position: a kind drawn at random (the other one when it has none left), then a puzzle
     * of that kind around the player's puzzle rating.
     *
     * @throws SubjectUnavailableException nothing left to draw
     */
    private function serve(Run $run, \DateTimeImmutable $now): Attempt
    {
        $target = (int) round($this->ratings->findOneByUser($run->getUser())?->getRating() ?? RatingCalculator::DEFAULT_RATING);
        $kinds = $this->randomizer->shuffleArray(Kind::cases());
        foreach ($kinds as $kind) {
            $puzzleId = $this->picker->pick($run, $kind, $target);
            $puzzle = null === $puzzleId ? null : $this->catalog->find($puzzleId);
            $shown = null === $puzzle ? null : self::shown($puzzle, $kind);
            if (null === $puzzle || null === $shown) {
                continue;
            }
            // The position may take the rest of the run.
            $left = (int) ceil((float) $run->getExpiresAt()->format('U.u') - (float) $now->format('U.u'));
            $attempt = new Attempt($run, (int) $puzzle->getId(), $puzzle->getRating(), $kind, $shown['fen'], $shown['move'], max(1, $left), $now);
            $this->entityManager->persist($attempt);
            $this->entityManager->flush();

            return $attempt;
        }

        throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_position'], 'No sacrifice left to show.');
    }

    /**
     * Logs the verdict, in the transaction of the change.
     */
    private function log(Run $run, Attempt $attempt, \DateTimeImmutable $now): void
    {
        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::Brilliancy,
            success: AttemptStatus::Right === $attempt->getStatus(),
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'status' => $attempt->getStatus()->value,
                'kind' => $attempt->getKind()->value,
                'answer' => $attempt->getAnswer()?->value,
                'puzzle' => $attempt->getPuzzleId(),
                'rating' => $attempt->getPuzzleRating(),
                'seconds' => $attempt->getSeconds(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));
    }

    /**
     * The position to judge: the move shown, not what it is.
     */
    private function item(Attempt $attempt): Item
    {
        $counts = $this->attempts->countsOfRun($attempt->getRun());

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'position' => self::position($attempt),
            'servedAt' => $attempt->getServedAt()->format(\DATE_ATOM),
            'index' => $counts['played'] + 1,
            ...self::tally($counts),
        ]);
    }

    /**
     * @return array{fen: string, color: string, move: array{uci: string, san: string}}
     */
    private static function position(Attempt $attempt): array
    {
        $rules = Rules::fromFen($attempt->getFen());

        return [
            'fen' => $attempt->getFen(),
            'color' => 'w' === $rules->sideToMove() ? 'white' : 'black',
            'move' => ['uci' => $attempt->getMove(), 'san' => $rules->playUci($attempt->getMove())->san ?? $attempt->getMove()],
        ];
    }

    /**
     * The verdict and the correction: the puzzle's line from the move shown (the winning
     * combination, or the blunder and its punishment).
     *
     * @return array<string, mixed>
     */
    private function correction(Attempt $attempt, ?Puzzle $puzzle): array
    {
        $line = [];
        if (null !== $puzzle) {
            $rules = Rules::fromFen($attempt->getFen());
            foreach (\array_slice(explode(' ', $puzzle->getMoves()), $attempt->getKind()->ply()) as $uci) {
                $move = $rules->playUci($uci);
                if (null === $move) {
                    break;
                }
                $line[] = ['uci' => $uci, 'san' => $move->san ?? $uci];
            }
        }

        return [
            'status' => $attempt->getStatus()->value,
            'kind' => $attempt->getKind()->value,
            'answer' => $attempt->getAnswer()?->value,
            'line' => $line,
            'puzzle' => null === $puzzle ? null : [
                'id' => $puzzle->getLichessId(),
                'rating' => $puzzle->getRating(),
                'gameUrl' => $puzzle->getGameUrl(),
            ],
            'durationMs' => $attempt->getDurationMs(),
        ];
    }

    /**
     * The position and the move shown of a puzzle: its start and the opponent's move (a blunder),
     * or the position after it and the player's answer (brilliant). Null when the puzzle's moves
     * do not play.
     *
     * @return array{fen: string, move: string}|null
     */
    private static function shown(Puzzle $puzzle, Kind $kind): ?array
    {
        $moves = explode(' ', $puzzle->getMoves());
        if (\count($moves) < 2) {
            return null;
        }
        $rules = Rules::fromFen($puzzle->getFen());
        if (Kind::Brilliant === $kind && null === $rules->playUci($moves[0])) {
            return null;
        }

        return ['fen' => $rules->fen(), 'move' => $moves[$kind->ply()]];
    }

    /**
     * @param array{right: int, wrong: int} $counts
     *
     * @return array{right: int, wrong: int}
     */
    private static function tally(array $counts): array
    {
        return ['right' => $counts['right'], 'wrong' => $counts['wrong']];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{}
     *
     * @throws InvalidRunConfigException
     */
    private static function check(array $config): array
    {
        if ([] !== $config) {
            throw new InvalidRunConfigException('No option is accepted.');
        }

        return [];
    }
}
