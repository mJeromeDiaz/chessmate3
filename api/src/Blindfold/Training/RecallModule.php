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
use App\Chess\RandomLine;
use App\Chess\Rules;
use App\Entity\Blindfold\RecallAttempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Blindfold\RecallMode;
use App\Enum\Blindfold\RecallStatus;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Distraction;
use App\Enum\Training\Module;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Repository\Blindfold\RecallAttemptRepository;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Module\CountedModuleInterface;
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
 * level without a puzzle to draw, no run. A run of a mind map step ({@see Run::isPathStep()}), and
 * only such a run, may take options of its own (docs/BLINDFOLD_RECALL.md, § 3 bis): `count` (the
 * positions, the run's end), `lives` (pieces wrong or missing, the run closes when they are spent),
 * `coordinates` (false: a board without them), `levels` (the level of each position in turn),
 * `visibleSeconds` (its own time to memorize), `distraction` (a boss's, for the screen), `announce`
 * (moves of both sides announced after a generated position, the one to rebuild being the one they
 * reach); its levels need not be open.
 *
 * @phpstan-type Announced list<array{uci: string, san: string}>
 */
final class RecallModule implements TimeboxedModuleInterface, ReviewableModuleInterface, CountedModuleInterface
{
    public const SUBJECT_TYPE = 'blindfold_recall_player';
    public const ITEM_TYPE = 'blindfold_recall';
    public const SOURCE_TYPE = 'blindfold_recall_attempt';
    /** A session step's level: the highest one open when the step starts. */
    public const MY_LEVEL = 'mine';
    /** The options only a mind map step gives its run. */
    private const PATH_OPTIONS = ['count', 'lives', 'coordinates', 'levels', 'visibleSeconds', 'distraction', 'announce'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly RecallAttemptRepository $attempts,
        private readonly PositionGenerator $generator,
        private readonly RandomLine $line,
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

    public function isCounted(array $config): bool
    {
        return isset($config['count']);
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
        // A mind map step opens its levels; nobody else may give its options.
        if (!$run->isPathStep()) {
            if ([] !== array_intersect(array_keys($run->getConfig()), self::PATH_OPTIONS)) {
                throw new InvalidRunConfigException(\sprintf('%s: on a mind map path only.', implode(', ', self::PATH_OPTIONS)));
            }
            $this->assertUnlocked($run->getUser(), $level);
        }
        // Served now: a puzzle level with nothing to draw refuses the run instead of opening an empty one.
        $this->serve($run, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        $attempt = $this->attempts->findPendingOfRun($run) ?? $this->serve($run, $now);

        return $this->item($run, $attempt);
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

        $options = self::options($run->getConfig());
        $stats = $this->attempts->statsOfRun($run);
        $resolved = $stats['exact'] + $stats['close'] + $stats['missed'];
        $closes = match (true) {
            null !== $options['lives'] && $stats['errors'] >= $options['lives'] => 'lives_out',
            null !== $options['count'] && $resolved >= $options['count'] => 'count_done',
            default => null,
        };

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: RecallStatus::Exact === $grade->status,
            closes: null === $closes ? null : CloseReason::SubjectFinished,
            context: null === $closes ? [] : ['reason' => $closes],
            data: [
                'position' => $resolved,
                'count' => $options['count'],
                'lives' => null === $options['lives'] ? null : max(0, $options['lives'] - $stats['errors']),
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
        $lives = self::options($config)['lives'];

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
                'count' => self::options($config)['count'],
                // A mind map boss's failures: its pieces wrong or missing (docs/MIND_MAP.md, § 4).
                'failures' => null === $lives ? null : $stats['errors'],
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
        $level = $this->levelOf($run);
        $rules = RecallRules::level($level);
        $options = self::options($run->getConfig());
        if (null === $rules['puzzlePieces']) {
            [$fen, $moves, $reached] = $this->generated($rules['pieces'], $options['announce']);
            $placement = explode(' ', $reached)[0];
            $attempt = new RecallAttempt($run, $level, $fen, $placement, $moves, null, \count(Placement::squares($placement)), $now, $options['visibleSeconds']);
        } else {
            $position = $this->picker->pick($run, $level);
            if (null === $position) {
                throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_puzzle'], 'No puzzle fits this level.');
            }
            $attempt = new RecallAttempt($run, $level, $position->fen, $position->expectedPlacement, $position->announced, $position->puzzleId, \count(Placement::squares($position->expectedPlacement)), $now, self::options($run->getConfig())['visibleSeconds']);
        }
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function item(Run $run, RecallAttempt $attempt): Item
    {
        $level = $attempt->getLevel();
        $mode = $run->getConfig()['mode'] ?? null;
        $options = self::options($run->getConfig());
        $stats = $this->attempts->statsOfRun($run);

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'position' => $stats['exact'] + $stats['close'] + $stats['missed'] + 1,
            'count' => $options['count'],
            'lives' => null === $options['lives'] ? null : max(0, $options['lives'] - $stats['errors']),
            'coordinates' => $options['coordinates'],
            'distraction' => $options['distraction'],
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
     * A generated position, and the $announce random legal moves played from it (a mind map step's
     * `announce`): the position shown, the moves, the position to rebuild.
     *
     * @param list<string> $pieces
     *
     * @return array{string, list<string>, string}
     */
    private function generated(array $pieces, int $announce): array
    {
        for ($try = 0; $try < RecallRules::GENERATION_TRIES; ++$try) {
            $fen = $this->generator->generate($pieces);
            if (0 === $announce) {
                return [$fen, [], $fen];
            }
            try {
                $line = $this->line->draw($fen, $announce);
            } catch (InvalidPositionException) {
                continue;
            }
            if (null !== $line) {
                return [$fen, $line['moves'], $line['fen']];
            }
        }
        throw new \RuntimeException('No line could be drawn.');
    }

    /**
     * The level of the next position: the run's, or its turn in `levels`.
     */
    private function levelOf(Run $run): int
    {
        [$level] = self::check($run->getConfig());
        $levels = self::options($run->getConfig())['levels'];
        if ([] === $levels) {
            return $level;
        }
        $stats = $this->attempts->statsOfRun($run);

        return $levels[min($stats['exact'] + $stats['close'] + $stats['missed'], \count($levels) - 1)];
    }

    /**
     * A mind map step's options (checked by {@see self::check()}).
     *
     * @param array<string, mixed> $config
     *
     * @return array{count: int|null, lives: int|null, coordinates: bool, levels: list<int>, visibleSeconds: int|null, distraction: string, announce: int}
     */
    private static function options(array $config): array
    {
        $count = $config['count'] ?? null;
        $lives = $config['lives'] ?? null;
        $levels = $config['levels'] ?? [];
        $visible = $config['visibleSeconds'] ?? null;

        return [
            'count' => \is_int($count) ? $count : null,
            'lives' => \is_int($lives) ? $lives : null,
            'coordinates' => false !== ($config['coordinates'] ?? true),
            'levels' => \is_array($levels) ? array_values(array_filter($levels, 'is_int')) : [],
            'visibleSeconds' => \is_int($visible) ? $visible : null,
            'distraction' => \is_string($config['distraction'] ?? null) ? $config['distraction'] : Distraction::None->value,
            'announce' => \is_int($config['announce'] ?? null) ? $config['announce'] : 0,
        ];
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
        if ([] !== array_diff(array_keys($config), ['level', 'mode', ...self::PATH_OPTIONS])) {
            throw new InvalidRunConfigException(\sprintf('Unknown option: only level, mode, %s are accepted.', implode(', ', self::PATH_OPTIONS)));
        }
        foreach (['count' => RecallRules::MAX_COUNT, 'lives' => RecallRules::MAX_LIVES] as $option => $max) {
            $value = $config[$option] ?? null;
            if (null !== $value && (!\is_int($value) || $value < 1 || $value > $max)) {
                throw new InvalidRunConfigException(\sprintf('%s must be a whole number from 1 to %d.', $option, $max));
            }
        }
        $visible = $config['visibleSeconds'] ?? null;
        [$fewest, $most] = RecallRules::PATH_VISIBLE_SECONDS;
        if (null !== $visible && (!\is_int($visible) || $visible < $fewest || $visible > $most)) {
            throw new InvalidRunConfigException(\sprintf('visibleSeconds must be a whole number from %d to %d.', $fewest, $most));
        }
        $announce = $config['announce'] ?? null;
        if (null !== $announce && (!\is_int($announce) || $announce < 1 || $announce > RecallRules::MAX_ANNOUNCE)) {
            throw new InvalidRunConfigException(\sprintf('announce must be a whole number from 1 to %d.', RecallRules::MAX_ANNOUNCE));
        }
        $distraction = $config['distraction'] ?? null;
        if (null !== $distraction && (!\is_string($distraction) || null === Distraction::tryFrom($distraction))) {
            throw new InvalidRunConfigException(\sprintf('distraction must be one of %s.', implode(', ', Distraction::values())));
        }
        if (\array_key_exists('coordinates', $config) && !\is_bool($config['coordinates'])) {
            throw new InvalidRunConfigException('coordinates must be a boolean.');
        }
        $levels = $config['levels'] ?? [];
        if (!\is_array($levels) || !array_is_list($levels) || \count($levels) > RecallRules::MAX_COUNT
            || [] !== array_filter($levels, static fn (mixed $l): bool => !\is_int($l) || !RecallRules::exists($l))) {
            throw new InvalidRunConfigException(\sprintf('levels must be a list of levels from 1 to %d.', RecallRules::MAX_LEVEL));
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
