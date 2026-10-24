<?php

declare(strict_types=1);

namespace App\Endgame\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Endgame\EndgameRules;
use App\Endgame\Judge\GameJudge;
use App\Endgame\Lesson\Catalogue;
use App\Endgame\Lesson\Lesson;
use App\Endgame\Pool\PositionPool;
use App\Endgame\Tablebase\Tablebase;
use App\Entity\Endgame\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Endgame\AttemptStatus;
use App\Enum\Endgame\Family;
use App\Enum\Endgame\Goal;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Repository\Endgame\AttemptRepository;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Puzzle\Rating\RatingCalculator;
use App\Repository\Puzzle\RatingRepository;
use App\Training\Module\BudgetChoicesInterface;
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
use Random\Randomizer;
use Symfony\Component\Uid\Uuid;

/**
 * The endgames module in timed runs (docs/ENDGAMES.md, § 5). The subject is the user; config
 * `mode` (learn: each lesson's textbook position then {@see EndgameRules::LEARN_VARIANTS} variants;
 * review: variants of lessons at random), `themes` ({@see Family} values) or `lessons` (keys, the
 * free page's choice), and `elo` (Maia's; {@see EndgameRules::MAX_ELO}: the tablebase's best
 * defence). A run lasts one of {@see EndgameRules::RUN_MINUTES}; positions follow one another until
 * its time is up, the one on screen at the end not counted.
 *
 * The browser plays the game (Maia, the tablebase's answers asked after each move) and sends it
 * whole; {@see GameJudge} judges it again from the answers kept.
 *
 * A run of the endgames path (docs/ENDGAMES.md, § 10; {@see Run::isPathStep()}) also takes
 * `count` (the positions, the run's end, instead of its time), `lives` (it closes at that many
 * positions missed), `variants` (learn mode: the variants after each textbook position) and may
 * leave `elo` out: Maia at the Elo proposed to the player ({@see EndgameRules::proposedElo()}).
 * The Roi des cendres also takes `ashes` (the mini-parcours' lessons, {@see AshesDraw}): a clock
 * per position held by the server (a late submission is a timeout, a life lost), margins tighter
 * ({@see EndgameRules::ashesBudget()}), only short mates, a tier of distractions per position.
 *
 * @phpstan-import-type PoolPosition from PositionPool
 *
 * @phpstan-type Config array{mode: string, themes: list<string>, lessons: list<string>, elo: int|null, count: int|null, lives: int|null, variants: int, ashes: list<list<string>>|null}
 */
final class EndgameModule implements TimeboxedModuleInterface, ReviewableModuleInterface, BudgetChoicesInterface, CountedModuleInterface
{
    public const SUBJECT_TYPE = 'endgame_player';
    public const ITEM_TYPE = 'endgame_position';
    public const SOURCE_TYPE = 'endgame_attempt';
    public const MODES = ['learn', 'review'];
    /** The options of a path's run only. */
    private const PATH_OPTIONS = ['count', 'lives', 'variants', 'ashes'];
    /** A path's run: positions, lives and variants at most. */
    private const MAX_COUNT = 20;
    private const MAX_LIVES = 3;
    private const MAX_VARIANTS = 9;

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AttemptRepository $attempts,
        private readonly PositionPool $pool,
        private readonly Tablebase $tablebase,
        private readonly GameJudge $judge,
        private readonly EventPublisher $events,
        private readonly RatingRepository $ratings,
    ) {
    }

    public function module(): Module
    {
        return Module::Endgames;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function allowedBudgetSeconds(): array
    {
        return EndgameRules::budgets();
    }

    public function isCounted(array $config): bool
    {
        return isset($config['count']);
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        $config = self::freeRun(self::check($settings));

        return new PreparedStep($user->getId(), ['mode' => $config['mode'], 'themes' => $config['themes'], 'lessons' => $config['lessons'], 'elo' => $config['elo']]);
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        $config = self::check($run->getConfig());
        if (!$run->isPathStep()) {
            self::freeRun($config);
        }
        if (null === $config['count'] && !EndgameRules::allows($run->getBudgetSeconds())) {
            throw new InvalidRunConfigException(\sprintf('A run lasts %s minutes.', implode(', ', EndgameRules::RUN_MINUTES)));
        }
        $this->serve($run, $config, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        $config = self::check($run->getConfig());
        // A reload gives the same position back, to play again from its start (the Roi des
        // cendres' clock running: once it is out, the position is lost and the next one served).
        $attempt = $this->attempts->findPendingOfRun($run);
        if (null !== $attempt && $this->isLate($attempt, $config, $now)) {
            $this->timeOut($run, $attempt, [], $now);
            $closes = $this->closing($run, $config);
            if (null !== $closes) {
                throw new SubjectUnavailableException(CloseReason::SubjectFinished, ['reason' => $closes]);
            }
            $attempt = null;
        }

        return $this->item($attempt ?? $this->serve($run, $config, $now));
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
        $lesson = Catalogue::get($attempt->getLessonKey()) ?? throw new ItemNotFoundException();
        $config = self::check($run->getConfig());

        if ($this->isLate($attempt, $config, $now)) {
            $this->timeOut($run, $attempt, $submission->moves, $now);
        } else {
            $verdict = $this->judge->judge($lesson, $attempt->getFen(), $submission->moves, $attempt->getBudgetMoves(), $attempt->getHoldMoves(), null === $attempt->getOpponentElo(), $submission->pass, $submission->engine);
            $attempt->resolve($verdict->status, $submission->moves, $verdict->mistakeAt, $verdict->bestMove, $verdict->playerMoves, $verdict->engineJudged, $now);
            $this->entityManager->flush();
            $this->publish($run, $attempt, $lesson, $now);
        }

        $resolved = $this->attempts->findResolvedOfRun($run);
        $failures = $this->failuresOf($run);
        $closes = $this->closing($run, $config);

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: $attempt->getStatus()->isSuccess(),
            data: [
                ...self::correction($attempt),
                'position' => \count($resolved),
                'count' => $config['count'],
                'lives' => null === $config['lives'] ? null : max(0, $config['lives'] - $failures),
            ],
            closes: null === $closes ? null : CloseReason::SubjectFinished,
            context: null === $closes ? [] : ['reason' => $closes],
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
        $stats = array_fill_keys(array_map(static fn (AttemptStatus $status): string => $status->value, AttemptStatus::cases()), 0);
        $engineJudged = 0;
        $attempts = $this->attempts->findResolvedOfRun($run);
        foreach ($attempts as $attempt) {
            ++$stats[$attempt->getStatus()->value];
            $engineJudged += $attempt->isEngineJudged() ? 1 : 0;
        }
        unset($stats[AttemptStatus::Pending->value]);

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: \count($attempts),
            successCount: $stats[AttemptStatus::Won->value] + $stats[AttemptStatus::Held->value],
            metrics: [...$stats, 'engineJudged' => $engineJudged],
        );
    }

    public function review(Run $run): array
    {
        return array_map(static fn (Attempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            $attempt->getStatus()->isSuccess() ? ReviewItem::OK : ReviewItem::FAIL,
            $attempt->getDurationMs(),
            ['fen' => $attempt->getFen(), ...self::correction($attempt)],
        ), $this->attempts->findResolvedOfRun($run));
    }

    /**
     * The next position: in learn mode, the lessons in order, each one's textbook position then
     * its `variants`; in review mode, a variant of a lesson at random. A lesson without variants in
     * the pool serves its textbook position.
     *
     * @param Config $config
     */
    private function serve(Run $run, array $config, \DateTimeImmutable $now): Attempt
    {
        $lessons = self::lessons($config);
        $served = $this->attempts->servedPositionIds($run);
        $randomizer = new Randomizer();
        $position = null;
        $index = $this->attempts->countResolvedOfRun($run);
        if (null !== $config['ashes']) {
            [$lesson, $position] = $this->ashesPosition($run, $config['ashes'], $config['count'] ?? \count($lessons), $index, $served, $randomizer);
        } elseif ('learn' === $config['mode']) {
            $lesson = $lessons[intdiv($index, 1 + $config['variants']) % \count($lessons)];
            $position = 0 === $index % (1 + $config['variants']) ? null : $this->pool->pick($lesson, $randomizer, $served);
        } else {
            $lesson = $lessons[0];
            foreach ($randomizer->shuffleArray($lessons) as $candidate) {
                $lesson = $candidate;
                $position = $this->pool->pick($candidate, $randomizer, $served);
                if (null !== $position) {
                    break;
                }
            }
        }

        $fen = $position['fen'] ?? $lesson->textbook;
        // Kept answers only: no call to Lichess inside the run's transaction.
        $dtm = null === $position ? $this->tablebase->lookup($fen)?->dtm : $position['dtm'];
        $elo = $config['elo'] ?? EndgameRules::proposedElo($this->ratings->findOneByUser($run->getUser())?->getRating() ?? RatingCalculator::DEFAULT_RATING);
        $attempt = new Attempt(
            $run,
            $lesson->key,
            $fen,
            $position['id'] ?? null,
            null === $position,
            EndgameRules::isPerfect($elo) ? null : $elo,
            match (true) {
                Goal::Hold === $lesson->goal => null,
                null !== $config['ashes'] => EndgameRules::ashesBudget($dtm, $index + 1),
                default => EndgameRules::budgetMoves($dtm),
            },
            Goal::Hold === $lesson->goal ? $lesson->holdMoves : null,
            $now,
        );
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function item(Attempt $attempt): Item
    {
        $lesson = Catalogue::get($attempt->getLessonKey());
        $config = self::check($attempt->getRun()->getConfig());
        $index = $this->attempts->countResolvedOfRun($attempt->getRun()) + 1;

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'fen' => $attempt->getFen(),
            'lesson' => $attempt->getLessonKey(),
            'family' => $lesson?->family->value,
            'goal' => $lesson?->goal->value,
            'result' => $lesson?->result->value,
            'textbook' => $attempt->isTextbook(),
            // Learn mode allows hints (a move that keeps the result, free).
            'mode' => $config['mode'],
            // Lizy explains the lesson before its first position, in learn mode (the textbook
            // one; it comes back in place of a variant when the pool has none, unexplained).
            'explain' => 'learn' === $config['mode'] && $attempt->isTextbook() && 0 === ($index - 1) % (1 + $config['variants']),
            'budgetMoves' => $attempt->getBudgetMoves(),
            'holdMoves' => $attempt->getHoldMoves(),
            'opponent' => ['elo' => $attempt->getOpponentElo(), 'perfect' => null === $attempt->getOpponentElo()],
            'servedAt' => $attempt->getServedAt()->format(\DATE_ATOM),
            'index' => $index,
            // A path's run: its positions and the lives left, null otherwise.
            'count' => $config['count'],
            'lives' => null === $config['lives'] ? null : max(0, $config['lives'] - $this->failuresOf($attempt->getRun())),
            // The Roi des cendres: the position's clock, its end (UTC) and the distractions' tier.
            'clockSeconds' => null === $config['ashes'] ? null : EndgameRules::ashesClock($index),
            'deadline' => null === $config['ashes'] ? null : $this->deadline($attempt, $index)->format(\DATE_ATOM),
            'tier' => null === $config['ashes'] ? null : EndgameRules::ashesTier($index),
        ]);
    }

    /**
     * The Roi des cendres' position: the $index-th lesson drawn (0-based), a variant (a short one
     * for a mate) or its textbook position; when it has none, another lesson of its mini-parcours,
     * else any other, never one already played in the run (one drawn for a later position last).
     *
     * @param list<list<string>> $groups
     * @param list<int>          $served
     *
     * @return array{Lesson, PoolPosition|null}
     */
    private function ashesPosition(Run $run, array $groups, int $count, int $index, array $served, Randomizer $randomizer): array
    {
        $drawn = AshesDraw::lessons($groups, $count, $run->getId()->toBinary());
        $key = $drawn[$index % max(1, \count($drawn))] ?? $groups[0][0];
        $played = array_map(static fn (Attempt $done): string => $done->getLessonKey(), $this->attempts->findResolvedOfRun($run));
        $group = array_values(array_filter($groups, static fn (array $keys): bool => \in_array($key, $keys, true)))[0] ?? [];
        // Its mini-parcours first, the lessons not drawn before those drawn for a later position.
        $all = array_merge(...$groups);
        $candidates = array_values(array_unique([
            $key,
            ...self::spare($group, [...$drawn, ...$played], $randomizer),
            ...self::spare($group, $played, $randomizer),
            ...self::spare($all, [...$drawn, ...$played], $randomizer),
            ...self::spare($all, $played, $randomizer),
        ]));

        foreach ($candidates as $candidate) {
            $lesson = Catalogue::get($candidate);
            if (null === $lesson) {
                continue;
            }
            $maxDtm = Goal::Mate === $lesson->goal ? EndgameRules::ASHES_MAX_MATE_PLIES : null;
            $position = $this->pool->pick($lesson, $randomizer, $served, $maxDtm);
            if (null !== $position) {
                return [$lesson, $position];
            }
            $dtm = null === $maxDtm ? null : $this->tablebase->lookup($lesson->textbook)?->dtm;
            if (null === $maxDtm || (null !== $dtm && abs($dtm) <= $maxDtm)) {
                return [$lesson, null];
            }
        }

        return [Catalogue::get($key) ?? throw new InvalidRunConfigException('No lesson to play.'), null];
    }

    /**
     * The lessons of $keys not in $kept, shuffled.
     *
     * @param list<string> $keys
     * @param list<string> $kept
     *
     * @return list<string>
     */
    private static function spare(array $keys, array $kept, Randomizer $randomizer): array
    {
        return $randomizer->shuffleArray(array_values(array_diff($keys, $kept)));
    }

    /**
     * The Roi des cendres: the end of a position's clock.
     */
    private function deadline(Attempt $attempt, int $index): \DateTimeImmutable
    {
        return $attempt->getServedAt()->modify(\sprintf('+%d seconds', EndgameRules::ashesClock($index)));
    }

    /**
     * The Roi des cendres: the pending position's clock out, beyond the network tolerance.
     *
     * @param Config $config
     */
    private function isLate(Attempt $attempt, array $config, \DateTimeImmutable $now): bool
    {
        if (null === $config['ashes']) {
            return false;
        }
        $index = $this->attempts->countResolvedOfRun($attempt->getRun()) + 1;

        return $now > $this->deadline($attempt, $index)->modify(\sprintf('+%d seconds', EndgameRules::ASHES_TOLERANCE_SECONDS));
    }

    /**
     * The position lost on time, the moves sent (if any) kept for the review.
     *
     * @param list<string> $moves
     */
    private function timeOut(Run $run, Attempt $attempt, array $moves, \DateTimeImmutable $now): void
    {
        $attempt->resolve(AttemptStatus::Timeout, $moves, null, null, intdiv(\count($moves) + 1, 2), false, $now);
        $this->entityManager->flush();
        $lesson = Catalogue::get($attempt->getLessonKey());
        if (null !== $lesson) {
            $this->publish($run, $attempt, $lesson, $now);
        }
    }

    /**
     * Why a path's run closes now (its lives out, its positions done), null when it goes on.
     *
     * @param Config $config
     */
    private function closing(Run $run, array $config): ?string
    {
        return match (true) {
            null !== $config['lives'] && $this->failuresOf($run) >= $config['lives'] => 'lives_out',
            null !== $config['count'] && $this->attempts->countResolvedOfRun($run) >= $config['count'] => 'count_done',
            default => null,
        };
    }

    private function failuresOf(Run $run): int
    {
        return \count(array_filter($this->attempts->findResolvedOfRun($run), static fn (Attempt $done): bool => !$done->getStatus()->isSuccess()));
    }

    private function publish(Run $run, Attempt $attempt, Lesson $lesson, \DateTimeImmutable $now): void
    {
        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::Endgame,
            success: $attempt->getStatus()->isSuccess(),
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'status' => $attempt->getStatus()->value,
                'lesson' => $lesson->key,
                'family' => $lesson->family->value,
                'opponentElo' => $attempt->getOpponentElo(),
                'textbook' => $attempt->isTextbook(),
                'playerMoves' => $attempt->getPlayerMoves(),
                'engineJudged' => $attempt->isEngineJudged(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));
    }

    /**
     * The verdict and the correction.
     *
     * @return array<string, mixed>
     */
    private static function correction(Attempt $attempt): array
    {
        return [
            'status' => $attempt->getStatus()->value,
            'lesson' => $attempt->getLessonKey(),
            'moves' => $attempt->getMoves() ?? [],
            'mistakeAt' => $attempt->getMistakeAt(),
            'bestMove' => $attempt->getBestMove(),
            'playerMoves' => $attempt->getPlayerMoves(),
            'budgetMoves' => $attempt->getBudgetMoves(),
            'holdMoves' => $attempt->getHoldMoves(),
            'engineJudged' => $attempt->isEngineJudged(),
            'durationMs' => $attempt->getDurationMs(),
        ];
    }

    /**
     * The lessons a run plays: those chosen, else those of its themes, in the catalogue's order.
     *
     * @param Config $config
     *
     * @return non-empty-list<Lesson>
     */
    private static function lessons(array $config): array
    {
        $keys = [] !== $config['lessons'] ? $config['lessons'] : array_merge(...($config['ashes'] ?? []));
        $lessons = [] !== $keys
            ? array_values(array_filter(array_map(Catalogue::get(...), $keys)))
            : array_values(array_filter(Catalogue::all(), static fn (Lesson $lesson): bool => \in_array($lesson->family->value, $config['themes'], true)));

        return [] === $lessons ? throw new InvalidRunConfigException('No lesson to play.') : $lessons;
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return Config
     *
     * @throws InvalidRunConfigException
     */
    private static function check(array $config): array
    {
        if ([] !== array_diff(array_keys($config), ['mode', 'themes', 'lessons', 'elo', ...self::PATH_OPTIONS])) {
            throw new InvalidRunConfigException('Unknown option: only mode, themes, lessons, elo, count, lives, variants and ashes are accepted.');
        }
        $mode = $config['mode'] ?? null;
        if (!\is_string($mode) || !\in_array($mode, self::MODES, true)) {
            throw new InvalidRunConfigException(\sprintf('mode must be one of %s.', implode(', ', self::MODES)));
        }
        $families = array_map(static fn (Family $family): string => $family->value, Family::cases());
        $given = $config['themes'] ?? [];
        $themes = [];
        foreach (\is_array($given) && array_is_list($given) ? $given : [null] as $theme) {
            if (!\is_string($theme) || !\in_array($theme, $families, true) || \in_array($theme, $themes, true)) {
                throw new InvalidRunConfigException(\sprintf('themes must be distinct values among %s.', implode(', ', $families)));
            }
            $themes[] = $theme;
        }
        $lessons = $config['lessons'] ?? [];
        if (!\is_array($lessons) || !array_is_list($lessons) || [] !== array_filter($lessons, static fn (mixed $key): bool => !\is_string($key) || null === Catalogue::get($key))) {
            throw new InvalidRunConfigException('lessons must be keys of the catalogue.');
        }
        $ashes = $config['ashes'] ?? null;
        $isKey = static fn (mixed $key): bool => \is_string($key) && null !== Catalogue::get($key);
        if (null !== $ashes && (!\is_array($ashes) || [] === $ashes || !array_is_list($ashes) || [] !== array_filter($ashes, static fn (mixed $group): bool => !\is_array($group) || [] === $group || !array_is_list($group) || \count(array_filter($group, $isKey)) !== \count($group)))) {
            throw new InvalidRunConfigException('ashes must be groups of keys of the catalogue.');
        }
        if ([] === $themes && [] === $lessons && null === $ashes) {
            throw new InvalidRunConfigException('Choose themes or lessons.');
        }
        $count = self::limit($config, 'count', self::MAX_COUNT);
        if (null !== $ashes && null === $count) {
            throw new InvalidRunConfigException('ashes needs count.');
        }
        $elo = $config['elo'] ?? null;
        if (null !== $elo && (!\is_int($elo) || $elo < EndgameRules::MIN_ELO || $elo > EndgameRules::MAX_ELO)) {
            throw new InvalidRunConfigException(\sprintf('elo must be %d to %d.', EndgameRules::MIN_ELO, EndgameRules::MAX_ELO));
        }

        /** @var list<string> $lessons */
        return [
            'mode' => $mode,
            'themes' => $themes,
            'lessons' => $lessons,
            'elo' => $elo,
            'count' => $count,
            'lives' => self::limit($config, 'lives', self::MAX_LIVES),
            'variants' => self::limit($config, 'variants', self::MAX_VARIANTS) ?? EndgameRules::LEARN_VARIANTS,
            'ashes' => null === $ashes ? null : array_map(static fn (mixed $group): array => \is_array($group) ? array_values(array_filter($group, \is_string(...))) : [], $ashes),
        ];
    }

    /**
     * A whole number from 1 to $max, null when absent.
     *
     * @param array<string, mixed> $config
     *
     * @throws InvalidRunConfigException
     */
    private static function limit(array $config, string $option, int $max): ?int
    {
        $value = $config[$option] ?? null;
        if (null !== $value && (!\is_int($value) || $value < 1 || $value > $max)) {
            throw new InvalidRunConfigException(\sprintf('%s must be 1 to %d.', $option, $max));
        }

        return $value;
    }

    /**
     * A run outside the path (free page, session): its Elo chosen, none of the path's options.
     *
     * @param Config $config
     *
     * @return Config
     *
     * @throws InvalidRunConfigException
     */
    private static function freeRun(array $config): array
    {
        if (null === $config['elo']) {
            throw new InvalidRunConfigException(\sprintf('elo must be %d to %d.', EndgameRules::MIN_ELO, EndgameRules::MAX_ELO));
        }
        if (null !== $config['count'] || null !== $config['lives'] || EndgameRules::LEARN_VARIANTS !== $config['variants'] || null !== $config['ashes']) {
            throw new InvalidRunConfigException('count, lives, variants and ashes are the endgames path\'s only.');
        }

        return $config;
    }
}
