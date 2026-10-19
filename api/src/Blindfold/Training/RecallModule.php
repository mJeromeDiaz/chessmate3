<?php

declare(strict_types=1);

namespace App\Blindfold\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Blindfold\Recall\Grader;
use App\Blindfold\Recall\Placement;
use App\Blindfold\Recall\PositionGenerator;
use App\Blindfold\Recall\Progress;
use App\Blindfold\Recall\PuzzlePositionPicker;
use App\Blindfold\Recall\RecallRules;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Blindfold\RecallAttempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Blindfold\RecallMode;
use App\Enum\Blindfold\RecallStatus;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Repository\Blindfold\RecallAttemptRepository;
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
 * Position recall in timed runs (docs/BLINDFOLD_RECALL.md). The subject is the user; config
 * `level` (1 to 20, open to the user) and `mode`: `free` (the recall page, budget
 * {@see RecallRules::FREE_BUDGET_SECONDS}, its clock hidden) or `timed`. A position is shown,
 * hidden, then rebuilt; the server corrects it square by square. The UNLOCK_EXACT-th exact rebuild
 * at a level opens the next one. Never rated. The first position is served at the start: a puzzle
 * level without a puzzle to draw, no run.
 *
 * @phpstan-type Announced list<array{uci: string, san: string}>
 */
final class RecallModule implements TimeboxedModuleInterface, ReviewableModuleInterface
{
    public const SUBJECT_TYPE = 'blindfold_recall_player';
    public const ITEM_TYPE = 'blindfold_recall';
    public const SOURCE_TYPE = 'blindfold_recall_attempt';
    /** A session step's level: the highest one open when the step starts. */
    public const MY_LEVEL = 'mine';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RecallAttemptRepository $attempts,
        private readonly PositionGenerator $generator,
        private readonly PuzzlePositionPicker $picker,
        private readonly Progress $progress,
        private readonly PuzzleCatalog $catalog,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::BlindfoldRecall;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        if ([] !== array_diff(array_keys($settings), ['level'])) {
            throw new InvalidRunConfigException('Unknown option: only level is accepted.');
        }
        $level = $settings['level'] ?? null;
        if (self::MY_LEVEL === $level) {
            $level = $this->progress->highest($user);
        }
        [$level] = self::check(['level' => $level, 'mode' => RecallMode::Timed->value]);
        $this->assertUnlocked($user, $level);

        return new PreparedStep($user->getId(), ['level' => $level, 'mode' => RecallMode::Timed->value]);
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        [$level, $mode] = self::check($run->getConfig());
        if (RecallMode::Free === $mode && RecallRules::FREE_BUDGET_SECONDS !== $run->getBudgetSeconds()) {
            throw new InvalidRunConfigException(\sprintf('A free run lasts %d seconds.', RecallRules::FREE_BUDGET_SECONDS));
        }
        $this->assertUnlocked($run->getUser(), $level);
        // Served now: a puzzle level with nothing to draw refuses the run instead of opening an empty one.
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
        $placement = [];
        foreach ($submission->placement as $piece) {
            if (isset($placement[$piece['square']])) {
                throw new InvalidItemSubmissionException(\sprintf('Two pieces placed on %s.', $piece['square']));
            }
            $placement[$piece['square']] = $piece['piece'];
        }
        $grade = Grader::grade(Placement::squares($attempt->getExpectedPlacement()), $placement);
        $attempt->resolve($grade, $placement, $now);
        $this->entityManager->flush();

        // The UNLOCK_EXACT-th exact rebuild at its level opens the next one (once: one run at a time).
        $level = $attempt->getLevel();
        if (RecallStatus::Exact === $grade->status && $level < RecallRules::MAX_LEVEL
            && RecallRules::UNLOCK_EXACT === $this->attempts->countExact($run->getUser(), $level)) {
            $attempt->markUnlocked();
            $this->entityManager->flush();
        }
        $puzzleId = $attempt->getPuzzleId();

        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::BlindfoldRecall,
            success: RecallStatus::Exact === $grade->status,
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'status' => $grade->status->value,
                'level' => $level,
                'stage' => RecallRules::stage($level),
                'expected' => $grade->expected,
                'correct' => $grade->correct,
                'wrong' => $grade->wrong,
                'extra' => $grade->extra,
                'missing' => $grade->missing,
                'accuracy' => $grade->accuracy,
                'unlocked' => $attempt->isUnlocked(),
                'puzzleId' => null === $puzzleId ? null : $this->catalog->get($puzzleId)->getLichessId(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: RecallStatus::Exact === $grade->status,
            data: [
                'status' => $grade->status->value,
                'expected' => $grade->expected,
                'correct' => $grade->correct,
                'wrong' => $grade->wrong,
                'extra' => $grade->extra,
                'missing' => $grade->missing,
                'accuracy' => $grade->accuracy,
                'expectedPlacement' => $attempt->getExpectedPlacement(),
                'unlockedLevel' => $attempt->isUnlocked() ? $level + 1 : null,
                'durationMs' => $attempt->getDurationMs(),
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
        $stats = $this->attempts->statsOfRun($run);
        $played = $stats['exact'] + $stats['close'] + $stats['missed'];
        $config = $run->getConfig();
        $level = \is_int($config['level'] ?? null) ? $config['level'] : null;

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $played,
            successCount: $stats['exact'],
            metrics: [
                'level' => $level,
                'stage' => null === $level || !RecallRules::exists($level) ? null : RecallRules::stage($level),
                'mode' => $config['mode'] ?? null,
                'exact' => $stats['exact'],
                'close' => $stats['close'],
                'missed' => $stats['missed'],
                'accuracy' => $played > 0 ? round($stats['accuracySum'] / $played, 2) : null,
                'unlockedLevel' => $stats['unlockedLevel'],
                'activeMs' => $stats['activeMs'],
                'averageMs' => $played > 0 ? intdiv($stats['activeMs'], $played) : null,
            ],
        );
    }

    public function review(Run $run): array
    {
        return array_map(static fn (RecallAttempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            match ($attempt->getStatus()) {
                RecallStatus::Exact => ReviewItem::OK,
                RecallStatus::Close => ReviewItem::HINT,
                default => ReviewItem::FAIL,
            },
            $attempt->getDurationMs(),
            [
                'level' => $attempt->getLevel(),
                'fen' => $attempt->getFen(),
                'announced' => self::announced($attempt),
                'expectedPlacement' => $attempt->getExpectedPlacement(),
                'placement' => Placement::fromSquares($attempt->getPlacement() ?? []),
                'expected' => $attempt->getPieceCount(),
                'correct' => $attempt->getCorrect(),
                'wrong' => $attempt->getWrong(),
                'extra' => $attempt->getExtra(),
                'missing' => $attempt->getMissing(),
                'accuracy' => $attempt->getAccuracy(),
            ],
        ), $this->attempts->findResolvedOfRun($run));
    }

    /**
     * A new position for the run: generated, or drawn from the puzzles.
     *
     * @throws SubjectUnavailableException no puzzle fits the level
     */
    private function serve(Run $run, \DateTimeImmutable $now): RecallAttempt
    {
        [$level] = self::check($run->getConfig());
        $rules = RecallRules::level($level);
        if (null === $rules['puzzlePieces']) {
            $fen = $this->generator->generate($rules['pieces']);
            $placement = explode(' ', $fen)[0];
            $attempt = new RecallAttempt($run, $level, $fen, $placement, [], null, \count(Placement::squares($placement)), $now);
        } else {
            $position = $this->picker->pick($run, $level);
            if (null === $position) {
                throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_puzzle'], 'No puzzle fits this level.');
            }
            $attempt = new RecallAttempt($run, $level, $position->fen, $position->expectedPlacement, $position->announced, $position->puzzleId, \count(Placement::squares($position->expectedPlacement)), $now);
        }
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function item(RecallAttempt $attempt): Item
    {
        $level = $attempt->getLevel();
        $mode = $attempt->getRun()->getConfig()['mode'] ?? null;

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'fen' => $attempt->getFen(),
            'level' => $level,
            'stage' => RecallRules::stage($level),
            'mode' => \is_string($mode) ? $mode : RecallMode::Timed->value,
            'visibleSeconds' => $attempt->getVisibleSeconds(),
            'hiddenSeconds' => RecallRules::HIDDEN_SECONDS,
            'announced' => self::announced($attempt),
            'startedAt' => $attempt->getStartedAt()->format(\DATE_ATOM),
        ]);
    }

    /**
     * The moves announced, with their SAN replayed from the position shown.
     *
     * @return Announced
     */
    private static function announced(RecallAttempt $attempt): array
    {
        if ([] === $attempt->getAnnounced()) {
            return [];
        }
        try {
            $rules = Rules::fromFen($attempt->getFen());
        } catch (InvalidPositionException) {
            return [];
        }
        $moves = [];
        foreach ($attempt->getAnnounced() as $uci) {
            $move = $rules->playUci($uci);
            if (null === $move) {
                break;
            }
            $moves[] = ['uci' => $uci, 'san' => $move->san ?? $uci];
        }

        return $moves;
    }

    /**
     * @throws SubjectUnavailableException the level is not open to the user yet
     */
    private function assertUnlocked(User $user, int $level): void
    {
        if (!$this->progress->isUnlocked($user, $level)) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'level_locked'], \sprintf('Level %d is not open yet.', $level));
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{int, RecallMode}
     *
     * @throws InvalidRunConfigException
     */
    private static function check(array $config): array
    {
        if ([] !== array_diff(array_keys($config), ['level', 'mode'])) {
            throw new InvalidRunConfigException('Unknown option: only level and mode are accepted.');
        }
        $level = $config['level'] ?? null;
        if (!\is_int($level) || !RecallRules::exists($level)) {
            throw new InvalidRunConfigException(\sprintf('level must be a whole number from 1 to %d.', RecallRules::MAX_LEVEL));
        }
        $mode = \is_string($config['mode'] ?? null) ? RecallMode::tryFrom($config['mode']) : null;
        if (null === $mode) {
            throw new InvalidRunConfigException(\sprintf('mode must be one of %s.', implode(', ', RecallMode::values())));
        }

        return [$level, $mode];
    }
}
