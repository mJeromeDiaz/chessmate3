<?php

declare(strict_types=1);

namespace App\PuzzleRush\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\ApiResource\Puzzle\PuzzleView;
use App\Entity\Catalog\Puzzle;
use App\Entity\PuzzleRush\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\PuzzleRush\AttemptStatus;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Gamification\Xp\XpRules;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Rating\RatingCalculator;
use App\Puzzle\Selection\SelectionUnavailableException;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Puzzle\Solution\SolutionValidator;
use App\PuzzleRush\Record\Records;
use App\PuzzleRush\RushRules;
use App\PuzzleRush\Selection\PuzzlePicker;
use App\Repository\Catalog\ThemeRepository;
use App\Repository\Puzzle\RatingRepository;
use App\Repository\PuzzleRush\AttemptRepository;
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
use Symfony\Component\Uid\Uuid;

/**
 * Puzzle rushes in timed runs (docs/PUZZLE_RUSH.md): as many puzzles as possible in 2, 3 or 5
 * minutes. The subject is the user; config `themes` (keys combined with OR, none: any puzzle). One
 * puzzle at a time, starting below the player's puzzle rating and harder with each one solved; one
 * try each: a wrong move, a hint or the solution is a miss and the next puzzle comes. Never rated.
 * The first puzzle is served at the start: none to draw, no run.
 *
 * Precision weighs as much as speed: the score is the puzzles solved minus
 * {@see RushRules::ERROR_WEIGHT} per miss. The run is one exercise, logged when it closes (its XP
 * by the score, within the daily cap); a record is the best score of a length, played until the
 * time was up without themes.
 */
final class RushModule implements TimeboxedModuleInterface, ReviewableModuleInterface, BudgetChoicesInterface
{
    public const SUBJECT_TYPE = 'puzzle_rush_player';
    public const ITEM_TYPE = 'rush_puzzle';
    public const SOURCE_TYPE = 'training_run';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AttemptRepository $attempts,
        private readonly PuzzlePicker $picker,
        private readonly PuzzleCatalog $catalog,
        private readonly ThemeRepository $themes,
        private readonly RatingRepository $ratings,
        private readonly SolutionValidator $validator,
        private readonly EventPublisher $events,
        private readonly Records $records,
    ) {
    }

    public function module(): Module
    {
        return Module::PuzzleRush;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function allowedBudgetSeconds(): array
    {
        return RushRules::BUDGETS;
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        $keys = self::keysFrom($settings);
        $this->themeIds($keys);

        return new PreparedStep($user->getId(), ['themes' => $keys]);
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        if (!\in_array($run->getBudgetSeconds(), RushRules::BUDGETS, true)) {
            throw new InvalidRunConfigException(\sprintf('A rush lasts %s seconds.', implode(', ', RushRules::BUDGETS)));
        }
        // Served now: nothing to draw refuses the run instead of opening an empty one.
        $this->serve($run, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
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
        try {
            $replay = $this->validator->replay($this->catalog->get($attempt->getPuzzleId()), $submission->moves);
        } catch (InvalidSubmissionException $e) {
            throw new InvalidItemSubmissionException($e->getMessage(), 0, $e);
        }
        // One try, no help: anything but the whole solution found alone is a miss.
        $solved = $replay->completed && 0 === $replay->mistakes && 0 === $submission->hintLevel && !$submission->solutionShown;
        $attempt->resolve($solved ? AttemptStatus::Solved : AttemptStatus::Failed, $submission->moves, $replay->mistakes, $now, $submission->thinkMs);
        $this->entityManager->flush();
        $counts = $this->attempts->countsOfRun($run);

        // No score while playing: the client shows the counts and the accuracy only.
        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: $solved,
            data: [
                'status' => $attempt->getStatus()->value,
                'mistakes' => $replay->mistakes,
                'durationMs' => $attempt->getDurationMs(),
                'solved' => $counts['solved'],
                'failed' => $counts['failed'],
            ],
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The puzzle on screen at the end is not counted.
        $pending = $this->attempts->findPendingOfRun($run);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
            $this->entityManager->flush();
        }
        $counts = $this->attempts->countsOfRun($run);
        if (0 === $counts['solved'] + $counts['failed']) {
            return;
        }
        // The runner closes a run whose time is up at its expiry, whenever that is noticed.
        $closedAt = CloseReason::TimeUp === $reason ? min($now, $run->getExpiresAt()) : $now;
        $accuracy = RushRules::accuracy($counts['solved'], $counts['failed']);
        // Same transaction as the closing: the exercise exists if and only if the run is closed.
        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::PuzzleRush,
            success: null !== $accuracy && $accuracy >= RushRules::SUCCESS_ACCURACY,
            durationMs: self::msBetween($run->getStartedAt(), $closedAt),
            itemCount: $counts['solved'] + $counts['failed'],
            sourceType: self::SOURCE_TYPE,
            sourceId: $run->getId()->toRfc4122(),
            occurredAt: $closedAt,
            metadata: [
                'solved' => $counts['solved'],
                'failed' => $counts['failed'],
                'score' => RushRules::score($counts['solved'], $counts['failed']),
                'accuracy' => $accuracy,
                'budgetSeconds' => $run->getBudgetSeconds(),
                'themes' => self::keysFrom($run->getConfig()),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $solved = 0;
        $failed = 0;
        $solvedMs = 0;
        $ratings = [];
        foreach ($this->attempts->findResolvedOfRun($run) as $attempt) {
            if (AttemptStatus::Solved === $attempt->getStatus()) {
                ++$solved;
                $solvedMs += min(RushRules::AVERAGE_CAP_MS, $attempt->getDurationMs() ?? 0);
                $ratings[] = $attempt->getPuzzleRating();
            } else {
                ++$failed;
            }
        }
        $durationMs = self::msBetween($run->getStartedAt(), $closedAt);
        $themes = self::keysFrom($run->getConfig());
        $score = RushRules::score($solved, $failed);
        // Played until the time was up (closed at its expiry), on every puzzle: comparable runs.
        $ranked = $closedAt >= $run->getExpiresAt() && [] === $themes && $solved + $failed > 0;
        $recordBefore = $this->records->bestOutside($run);

        return new Summary(
            durationMs: $durationMs,
            itemCount: $solved + $failed,
            successCount: $solved,
            metrics: [
                'solved' => $solved,
                'failed' => $failed,
                'accuracy' => RushRules::accuracy($solved, $failed),
                'puzzlesPerMinute' => $durationMs > 0 ? round($solved * 60_000 / $durationMs, 2) : null,
                'averageMs' => $solved > 0 ? intdiv($solvedMs, $solved) : null,
                'score' => $score,
                'errorWeight' => RushRules::ERROR_WEIGHT,
                // The XP of the rule, before the daily cap (the run's review gives what was gained).
                'xp' => XpRules::rush($solved, $failed),
                'hardestRating' => [] === $ratings ? null : max($ratings),
                'themes' => $themes,
                'ranked' => $ranked,
                'recordBefore' => $recordBefore,
                'record' => $ranked && $score > 0 && (null === $recordBefore || $score > $recordBefore),
            ],
        );
    }

    public function review(Run $run): array
    {
        $attempts = $this->attempts->findResolvedOfRun($run);
        $puzzles = $this->catalog->byIds(array_map(static fn (Attempt $attempt): int => $attempt->getPuzzleId(), $attempts));

        return array_map(fn (Attempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            AttemptStatus::Solved === $attempt->getStatus() ? ReviewItem::OK : ReviewItem::FAIL,
            $attempt->getDurationMs(),
            [
                'puzzle' => self::puzzle($puzzles[$attempt->getPuzzleId()] ?? $this->catalog->get($attempt->getPuzzleId())),
                'mistakes' => $attempt->getMistakes(),
                'position' => $attempt->getPosition(),
                'targetRating' => $attempt->getTargetRating(),
            ],
        ), $attempts);
    }

    /**
     * A new puzzle for the run, at the ramp's target: from below the player's rating for the
     * first, then STEP higher for each one solved.
     *
     * @throws SubjectUnavailableException nothing left to draw, or the selection is being rebuilt
     */
    private function serve(Run $run, \DateTimeImmutable $now): Attempt
    {
        $themeIds = $this->themeIds(self::keysFrom($run->getConfig()));
        $first = $this->attempts->findFirstOfRun($run);
        $start = $first?->getTargetRating()
            ?? RushRules::start($this->ratings->findOneByUser($run->getUser())?->getRating() ?? RatingCalculator::DEFAULT_RATING);
        $solved = $this->attempts->countsOfRun($run)['solved'];
        $target = RushRules::target($start, $solved);
        try {
            $puzzleId = $this->picker->pick($run, $themeIds, $target);
        } catch (SelectionUnavailableException) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'selection_unavailable'], 'The puzzle selection is being rebuilt.');
        }
        if (null === $puzzleId) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_puzzle'], 'No puzzle left at this level for these themes.');
        }
        $attempt = new Attempt($run, $puzzleId, $this->catalog->get($puzzleId)->getRating(), $solved, $target, $now);
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function item(Attempt $attempt): Item
    {
        $counts = $this->attempts->countsOfRun($attempt->getRun());

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'puzzle' => self::puzzle($this->catalog->get($attempt->getPuzzleId())),
            'targetRating' => $attempt->getTargetRating(),
            'position' => $attempt->getPosition(),
            'startedAt' => $attempt->getStartedAt()->format(\DATE_ATOM),
            'solved' => $counts['solved'],
            'failed' => $counts['failed'],
        ]);
    }

    /**
     * @return array{id: string, fen: string, moves: list<string>, playerColor: string, rating: int, themes: list<string>, gameUrl: string}
     */
    private static function puzzle(Puzzle $entity): array
    {
        $puzzle = PuzzleView::from($entity);

        return [
            'id' => $puzzle->id,
            'fen' => $puzzle->fen,
            'moves' => $puzzle->moves,
            'playerColor' => $puzzle->playerColor,
            'rating' => $puzzle->rating,
            'themes' => $puzzle->themes,
            'gameUrl' => $puzzle->gameUrl,
        ];
    }

    /**
     * @param list<string> $keys
     *
     * @return list<int>
     *
     * @throws InvalidRunConfigException
     */
    private function themeIds(array $keys): array
    {
        if ([] === $keys) {
            return [];
        }
        $themes = $this->themes->findByKeys($keys);
        if (\count($themes) !== \count($keys)) {
            throw new InvalidRunConfigException('Unknown theme.');
        }

        return array_map(static fn ($theme): int => (int) $theme->getId(), $themes);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<string>
     *
     * @throws InvalidRunConfigException
     */
    private static function keysFrom(array $config): array
    {
        if ([] !== array_diff(array_keys($config), ['themes'])) {
            throw new InvalidRunConfigException('Unknown option: only themes is accepted.');
        }
        $keys = $config['themes'] ?? [];
        if (!\is_array($keys) || !array_is_list($keys) || \count($keys) > RushRules::MAX_THEMES) {
            throw new InvalidRunConfigException(\sprintf('themes must be a list of at most %d theme keys.', RushRules::MAX_THEMES));
        }
        $clean = [];
        foreach ($keys as $key) {
            if (!\is_string($key) || '' === $key || \strlen($key) > 32) {
                throw new InvalidRunConfigException('themes must be a list of theme keys.');
            }
            $clean[] = $key;
        }

        return array_values(array_unique($clean));
    }

    private static function msBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return max(0, (int) round(((float) $to->format('U.u') - (float) $from->format('U.u')) * 1000));
    }
}
