<?php

declare(strict_types=1);

namespace App\Blindfold\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\ApiResource\Puzzle\PuzzleView;
use App\Blindfold\Puzzle\PuzzlePicker;
use App\Blindfold\Puzzle\PuzzleRules;
use App\Entity\Blindfold\PuzzleAttempt;
use App\Entity\Catalog\Puzzle;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Blindfold\AttemptStatus;
use App\Enum\Blindfold\PuzzleLevel;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Selection\SelectionUnavailableException;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Puzzle\Solution\SolutionValidator;
use App\Repository\Blindfold\PuzzleAttemptRepository;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
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
 * Blindfold puzzles in timed runs (docs/BLINDFOLD.md). The subject is the user; config `level`
 * (easy, medium, hard), `length` (2, 3 or 4+ player moves) and `visibleSeconds`. Each puzzle is shown, hidden, then solved from
 * memory; the client reports the moves tried, the server replays them: solved without a mistake,
 * "helped" after one mistake (and the peek that follows it), failed beyond. Never rated: the
 * puzzle rating is not touched. The first puzzle is served at the start: none in the range, no run.
 */
final class PuzzleModule implements TimeboxedModuleInterface, ReviewableModuleInterface
{
    public const SUBJECT_TYPE = 'blindfold_player';
    public const ITEM_TYPE = 'blindfold_puzzle';
    public const SOURCE_TYPE = 'blindfold_puzzle_attempt';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly PuzzleAttemptRepository $attempts,
        private readonly PuzzlePicker $picker,
        private readonly PuzzleCatalog $catalog,
        private readonly SolutionValidator $validator,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::Blindfold;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        [$level, $length, $visible] = self::check($settings);

        return new PreparedStep($user->getId(), ['level' => $level->value, 'length' => $length, 'visibleSeconds' => $visible]);
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        self::check($run->getConfig());
        // Served now: an empty range refuses the run instead of opening an empty one.
        $this->serve($run, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        $attempt = $this->attempts->findPendingOfRun($run) ?? $this->serve($run, $now);

        return $this->item($attempt);
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
        $puzzle = $this->catalog->get($attempt->getPuzzleId());
        try {
            $replay = $this->validator->replay($puzzle, $submission->moves);
        } catch (InvalidSubmissionException $e) {
            throw new InvalidItemSubmissionException($e->getMessage(), 0, $e);
        }
        $status = match (true) {
            $submission->solutionShown || !$replay->completed || $replay->mistakes > PuzzleRules::PEEKS => AttemptStatus::Failed,
            0 === $replay->mistakes => AttemptStatus::Solved,
            default => AttemptStatus::Helped,
        };
        $attempt->resolve($status, $submission->moves, $replay->mistakes, $now);
        $this->entityManager->flush();

        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::BlindfoldPuzzle,
            success: AttemptStatus::Solved === $status,
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'status' => $status->value,
                'puzzleId' => $puzzle->getLichessId(),
                'level' => $attempt->getLevel()->value,
                'length' => $attempt->getLength(),
                'visibleSeconds' => $attempt->getVisibleSeconds(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: AttemptStatus::Solved === $status,
            data: [
                'status' => $status->value,
                'mistakes' => $replay->mistakes,
                'durationMs' => $attempt->getDurationMs(),
            ],
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The puzzle on screen at the end is not counted.
        $pending = $this->attempts->findPendingOfRun($run);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
        }
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $stats = $this->attempts->statsOfRun($run);
        $played = $stats['solved'] + $stats['helped'] + $stats['failed'];
        $config = $run->getConfig();

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $played,
            successCount: $stats['solved'],
            metrics: [
                'level' => $config['level'] ?? null,
                'length' => $config['length'] ?? null,
                'visibleSeconds' => $config['visibleSeconds'] ?? null,
                'solved' => $stats['solved'],
                'helped' => $stats['helped'],
                'failed' => $stats['failed'],
                'activeMs' => $stats['activeMs'],
                'averageMs' => $played > 0 ? intdiv($stats['activeMs'], $played) : null,
            ],
        );
    }

    public function review(Run $run): array
    {
        $attempts = $this->attempts->findResolvedOfRun($run);
        $puzzles = $this->catalog->byIds(array_map(static fn (PuzzleAttempt $attempt): int => $attempt->getPuzzleId(), $attempts));

        return array_map(fn (PuzzleAttempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            match ($attempt->getStatus()) {
                AttemptStatus::Solved => ReviewItem::OK,
                AttemptStatus::Helped => ReviewItem::HINT,
                default => ReviewItem::FAIL,
            },
            $attempt->getDurationMs(),
            [
                'puzzle' => self::puzzle($puzzles[$attempt->getPuzzleId()] ?? $this->catalog->get($attempt->getPuzzleId())),
                'mistakes' => $attempt->getMistakes(),
                'level' => $attempt->getLevel()->value,
                'length' => $attempt->getLength(),
                'visibleSeconds' => $attempt->getVisibleSeconds(),
            ],
        ), $attempts);
    }

    /**
     * A new puzzle for the run.
     *
     * @throws SubjectUnavailableException none left in the range, or the selection is being rebuilt
     */
    private function serve(Run $run, \DateTimeImmutable $now): PuzzleAttempt
    {
        [$level, $length, $visible] = self::check($run->getConfig());
        try {
            $puzzleId = $this->picker->pick($run, $level, $length);
        } catch (SelectionUnavailableException) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'selection_unavailable'], 'The puzzle selection is being rebuilt.');
        }
        if (null === $puzzleId) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_puzzle'], 'No puzzle left in this range.');
        }
        $attempt = new PuzzleAttempt($run, $puzzleId, $level, $length, $visible, $now);
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function item(PuzzleAttempt $attempt): Item
    {
        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'puzzle' => self::puzzle($this->catalog->get($attempt->getPuzzleId())),
            'level' => $attempt->getLevel()->value,
            'length' => $attempt->getLength(),
            'visibleSeconds' => $attempt->getVisibleSeconds(),
            'hiddenSeconds' => PuzzleRules::HIDDEN_SECONDS,
            'peeks' => PuzzleRules::PEEKS,
            'startedAt' => $attempt->getStartedAt()->format(\DATE_ATOM),
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
     * @param array<string, mixed> $config
     *
     * @return array{PuzzleLevel, int, int}
     *
     * @throws InvalidRunConfigException
     */
    private static function check(array $config): array
    {
        if ([] !== array_diff(array_keys($config), ['level', 'length', 'visibleSeconds'])) {
            throw new InvalidRunConfigException('Unknown option: only level, length and visibleSeconds are accepted.');
        }
        $level = \is_string($config['level'] ?? null) ? PuzzleLevel::tryFrom($config['level']) : null;
        if (null === $level) {
            throw new InvalidRunConfigException(sprintf('level must be one of %s.', implode(', ', PuzzleLevel::values())));
        }
        $length = $config['length'] ?? null;
        if (!\is_int($length) || !isset(PuzzleRules::LENGTHS[$length])) {
            throw new InvalidRunConfigException(sprintf('length must be one of %s.', implode(', ', array_keys(PuzzleRules::LENGTHS))));
        }
        $visible = $config['visibleSeconds'] ?? null;
        if (!\is_int($visible) || !\in_array($visible, PuzzleRules::VISIBLE_SECONDS, true)) {
            throw new InvalidRunConfigException(sprintf('visibleSeconds must be one of %s.', implode(', ', PuzzleRules::VISIBLE_SECONDS)));
        }

        return [$level, $length, $visible];
    }
}
