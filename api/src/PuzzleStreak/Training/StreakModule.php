<?php

declare(strict_types=1);

namespace App\PuzzleStreak\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\ApiResource\Puzzle\PuzzleView;
use App\Entity\Catalog\Puzzle;
use App\Entity\PuzzleStreak\Attempt;
use App\Entity\PuzzleStreak\Streak;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Gamification\Trophy;
use App\Enum\PuzzleStreak\AttemptStatus;
use App\Enum\PuzzleStreak\PlayMode;
use App\Enum\PuzzleStreak\Ramp;
use App\Enum\PuzzleStreak\StreakStatus;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Selection\SelectionUnavailableException;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Puzzle\Solution\SolutionValidator;
use App\PuzzleStreak\Event\RecordBeaten;
use App\PuzzleStreak\Selection\PuzzlePicker;
use App\PuzzleStreak\Selection\Topic;
use App\PuzzleStreak\StreakRules;
use App\Repository\PuzzleStreak\AttemptRepository;
use App\Repository\PuzzleStreak\StreakRepository;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Module\Item;
use App\Training\Module\ItemResult;
use App\Training\Module\ItemSubmission;
use App\Training\Module\OvertimeModuleInterface;
use App\Training\Module\PreparedStep;
use App\Training\Module\ReviewableModuleInterface;
use App\Training\Module\ReviewItem;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Puzzle streaks in timed runs (docs/PUZZLE_STREAK.md). The subject is the user; config `ramp`
 * (ladder, tiers), `topic` ({@see Topic}) and `mode`: `single` (the streak page: one streak, the
 * run ends with it; budget {@see StreakRules::SINGLE_BUDGET_SECONDS}) or `timed` (a session step:
 * after a miss, a new streak starts from the easiest puzzle; the best one counts). No help: a
 * wrong move, a hint or the solution breaks the streak. Never rated. The first puzzle is served at
 * the start: none in the topic and range, no run.
 *
 * Overtime: time up while a streak is alive (a puzzle solved at least), the player may go on
 * (docs/TRAINING.md, "Prolongation"); once extended, the run ends with that streak.
 */
final class StreakModule implements TimeboxedModuleInterface, ReviewableModuleInterface, OvertimeModuleInterface
{
    public const SUBJECT_TYPE = 'puzzle_streak_player';
    public const ITEM_TYPE = 'streak_puzzle';
    public const SOURCE_TYPE = 'puzzle_streak_attempt';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly StreakRepository $streaks,
        private readonly AttemptRepository $attempts,
        private readonly PuzzlePicker $picker,
        private readonly PuzzleCatalog $catalog,
        private readonly SolutionValidator $validator,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::PuzzleStreak;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        // A session step plays timed streaks, whatever its settings say.
        [$ramp, $topic] = self::check([...$settings, 'mode' => PlayMode::Timed->value]);

        return new PreparedStep($user->getId(), ['ramp' => $ramp->value, 'topic' => $topic->key, 'mode' => PlayMode::Timed->value]);
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        [, , $mode] = self::check($run->getConfig());
        if (PlayMode::Single === $mode && StreakRules::SINGLE_BUDGET_SECONDS !== $run->getBudgetSeconds()) {
            throw new InvalidRunConfigException(\sprintf('A single streak lasts %d seconds.', StreakRules::SINGLE_BUDGET_SECONDS));
        }
        // Served now: an empty topic or range refuses the run instead of opening an empty one.
        $this->serve($run, $now, CloseReason::SubjectUnavailable);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        $attempt = $this->attempts->findPendingOfRun($run) ?? $this->serve($run, $now, CloseReason::SubjectFinished);

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
        // No help: a hint or the solution is a miss.
        $solved = $replay->completed && 0 === $replay->mistakes && 0 === $submission->hintLevel && !$submission->solutionShown;
        $status = $solved ? AttemptStatus::Solved : AttemptStatus::Failed;
        $attempt->resolve($status, $submission->moves, $replay->mistakes, $now);
        $streak = $attempt->getStreak();
        $record = false;
        if ($solved) {
            $streak->solved($attempt->getPuzzleRating());
        } else {
            $record = $this->end($streak, StreakStatus::Broken, $now);
        }
        $this->entityManager->flush();

        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::StreakPuzzle,
            success: $solved,
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'status' => $status->value,
                'puzzleId' => $puzzle->getLichessId(),
                'rating' => $attempt->getPuzzleRating(),
                'targetRating' => $attempt->getTargetRating(),
                'position' => $attempt->getPosition(),
                'ramp' => $streak->getRamp()->value,
                'topic' => $streak->getTopic(),
                'streakId' => $streak->getId()->toRfc4122(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));

        // The streak page, or overtime: the run ends with its streak.
        $closes = !$solved && (PlayMode::Single === self::mode($run) || $run->getExtensions() > 0) ? CloseReason::SubjectFinished : null;

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: $solved,
            data: [
                'status' => $status->value,
                'mistakes' => $replay->mistakes,
                'durationMs' => $attempt->getDurationMs(),
                'streak' => $streak->getLength(),
                'broken' => !$solved,
                'record' => $record,
                'best' => $this->bestOfRun($run),
            ],
            closes: $closes,
            context: null === $closes ? [] : ['reason' => 'streak_broken'],
        );
    }

    public function canExtend(Run $run): bool
    {
        return ($this->streaks->findActiveOfRun($run)?->getLength() ?? 0) > 0;
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The puzzle on screen at the end is not counted.
        $pending = $this->attempts->findPendingOfRun($run);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
            $this->entityManager->flush();
        }
        $streak = $this->streaks->findActiveOfRun($run);
        if (null === $streak) {
            return;
        }
        $lastAt = $this->attempts->lastSubmittedAt($streak);
        if (null === $lastAt) {
            // Not a puzzle played: no streak.
            $this->entityManager->remove($streak);

            return;
        }
        // Over at its last puzzle, however late the closing is noticed.
        $this->end($streak, StreakStatus::Ended, $lastAt);
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $config = $run->getConfig();
        $ramp = Ramp::tryFrom(\is_string($config['ramp'] ?? null) ? $config['ramp'] : '') ?? Ramp::Ladder;
        $topic = \is_string($config['topic'] ?? null) ? $config['topic'] : Topic::GENERAL;
        $solved = 0;
        $failed = 0;
        $activeMs = 0;
        foreach ($this->attempts->findResolvedOfRun($run) as $attempt) {
            if (AttemptStatus::Solved === $attempt->getStatus()) {
                ++$solved;
            } else {
                ++$failed;
            }
            $activeMs += min(StreakRules::ACTIVE_TIME_CAP_MS, $attempt->getDurationMs() ?? 0);
        }
        $streaks = array_values(array_filter($this->streaks->findOfRun($run), static fn (Streak $s): bool => !$s->isActive()));
        $lengths = array_map(static fn (Streak $s): int => $s->getLength(), $streaks);
        $ratings = array_filter(array_map(static fn (Streak $s): ?int => $s->getBestRating(), $streaks), static fn (?int $r): bool => null !== $r);
        $played = $solved + $failed;
        $best = [] === $lengths ? 0 : max($lengths);

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $played,
            successCount: $solved,
            metrics: [
                'mode' => $config['mode'] ?? null,
                'ramp' => $ramp->value,
                'topic' => $topic,
                'bestStreak' => $best,
                'streaks' => $lengths,
                'recordBefore' => $this->streaks->bestOutside($run, $ramp, $topic),
                'record' => [] !== array_filter($streaks, static fn (Streak $s): bool => $s->isRecord()),
                // The puzzle streak trophies this run won (shortest first).
                'badges' => array_map(static fn (Trophy $t): string => $t->value, Trophy::puzzleStreaksBetween($this->streaks->bestEverOutside($run), $best)),
                'hardestRating' => [] === $ratings ? null : max($ratings),
                'solved' => $solved,
                'failed' => $failed,
                'activeMs' => $activeMs,
                'averageMs' => $played > 0 ? intdiv($activeMs, $played) : null,
                'extensions' => $run->getExtensions(),
            ],
        );
    }

    public function review(Run $run): array
    {
        $attempts = $this->attempts->findResolvedOfRun($run);
        $puzzles = $this->catalog->byIds(array_map(static fn (Attempt $attempt): int => $attempt->getPuzzleId(), $attempts));
        $numbers = [];
        foreach ($this->streaks->findOfRun($run) as $i => $streak) {
            $numbers[$streak->getId()->toRfc4122()] = $i + 1;
        }

        return array_map(fn (Attempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            AttemptStatus::Solved === $attempt->getStatus() ? ReviewItem::OK : ReviewItem::FAIL,
            $attempt->getDurationMs(),
            [
                'puzzle' => self::puzzle($puzzles[$attempt->getPuzzleId()] ?? $this->catalog->get($attempt->getPuzzleId())),
                'mistakes' => $attempt->getMistakes(),
                'streakNumber' => $numbers[$attempt->getStreak()->getId()->toRfc4122()] ?? null,
                'position' => $attempt->getPosition(),
                'targetRating' => $attempt->getTargetRating(),
            ],
        ), $attempts);
    }

    /**
     * Ends a streak; a record (beating the user's best of its ramp and topic, RECORD_MIN puzzles
     * at least) is published for its XP bonus.
     *
     * @return bool whether it is a record
     */
    private function end(Streak $streak, StreakStatus $status, \DateTimeImmutable $at): bool
    {
        $previous = $this->streaks->bestBefore($streak);
        $record = $streak->getLength() >= StreakRules::RECORD_MIN && $streak->getLength() > $previous;
        $streak->end($status, $at, $record);
        if ($record) {
            $this->events->publish(new RecordBeaten(
                userId: $streak->getUser()->getId()->toRfc4122(),
                streakId: $streak->getId()->toRfc4122(),
                runId: $streak->getRun()->getId()->toRfc4122(),
                ramp: $streak->getRamp()->value,
                topic: $streak->getTopic(),
                length: $streak->getLength(),
                previous: $previous,
                occurredAt: $at,
            ));
        }

        return $record;
    }

    /** The longest streak of the run so far, the one in progress included. */
    private function bestOfRun(Run $run): int
    {
        return array_reduce($this->streaks->findOfRun($run), static fn (int $best, Streak $s): int => max($best, $s->getLength()), 0);
    }

    /**
     * A new puzzle for the run: of its streak in progress, else of a new one.
     *
     * @param CloseReason $emptyReason how the run closes when nothing is left to draw
     *
     * @throws SubjectUnavailableException nothing left in the topic and range, or the selection is being rebuilt
     */
    private function serve(Run $run, \DateTimeImmutable $now, CloseReason $emptyReason): Attempt
    {
        [$ramp, $topic] = self::check($run->getConfig());
        $streak = $this->streaks->findActiveOfRun($run);
        $solved = $streak?->getLength() ?? 0;
        try {
            $puzzleId = $this->picker->pick($run, $ramp, $topic, $solved);
        } catch (SelectionUnavailableException) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'selection_unavailable'], 'The puzzle selection is being rebuilt.');
        }
        if (null === $puzzleId) {
            throw new SubjectUnavailableException($emptyReason, ['reason' => 'no_puzzle'], 'No puzzle left at this level in this topic.');
        }
        if (null === $streak) {
            $streak = new Streak($run, $ramp, $topic->key, $now);
            $this->entityManager->persist($streak);
        }
        $attempt = new Attempt($streak, $puzzleId, $this->catalog->get($puzzleId)->getRating(), StreakRules::target($ramp, $solved), $now);
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function item(Attempt $attempt): Item
    {
        $streak = $attempt->getStreak();

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'puzzle' => self::puzzle($this->catalog->get($attempt->getPuzzleId())),
            'ramp' => $streak->getRamp()->value,
            'topic' => $streak->getTopic(),
            'mode' => self::mode($attempt->getRun())->value,
            'streak' => $streak->getLength(),
            'best' => $this->bestOfRun($attempt->getRun()),
            'targetRating' => $attempt->getTargetRating(),
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

    private static function mode(Run $run): PlayMode
    {
        $mode = $run->getConfig()['mode'] ?? null;

        return PlayMode::tryFrom(\is_string($mode) ? $mode : '') ?? PlayMode::Timed;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{Ramp, Topic, PlayMode}
     *
     * @throws InvalidRunConfigException
     */
    private static function check(array $config): array
    {
        if ([] !== array_diff(array_keys($config), ['ramp', 'topic', 'mode'])) {
            throw new InvalidRunConfigException('Unknown option: only ramp, topic and mode are accepted.');
        }
        $ramp = \is_string($config['ramp'] ?? null) ? Ramp::tryFrom($config['ramp']) : null;
        if (null === $ramp) {
            throw new InvalidRunConfigException(\sprintf('ramp must be one of %s.', implode(', ', Ramp::values())));
        }
        try {
            $topic = Topic::parse(\is_string($config['topic'] ?? null) ? $config['topic'] : '');
        } catch (\InvalidArgumentException $e) {
            throw new InvalidRunConfigException($e->getMessage(), 0, $e);
        }
        $mode = \is_string($config['mode'] ?? null) ? PlayMode::tryFrom($config['mode']) : null;
        if (null === $mode) {
            throw new InvalidRunConfigException(\sprintf('mode must be one of %s.', implode(', ', PlayMode::values())));
        }

        return [$ramp, $topic, $mode];
    }
}
