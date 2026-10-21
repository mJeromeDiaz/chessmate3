<?php

declare(strict_types=1);

namespace App\Calculation\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Calculation\CalculationRules;
use App\Calculation\Drawing\MatePicker;
use App\Calculation\Judge\IllegalLineException;
use App\Calculation\Judge\LineJudge;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Calculation\Attempt;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Calculation\AttemptStatus;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Rating\RatingCalculator;
use App\Repository\Calculation\AttemptRepository;
use App\Repository\Puzzle\RatingRepository;
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
use App\Training\Module\ReadyModuleInterface;
use App\Training\Module\ReviewableModuleInterface;
use App\Training\Module\ReviewItem;
use App\Training\Module\Summary;
use App\Training\Module\TimeboxedModuleInterface;
use App\Training\Run\TimeboxRunner;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The calculation module in timed runs (docs/CALCULATION.md), played from the mind map only for now
 * ({@see Run::isPathStep()}). The subject is the user; config `mate` (1 to 4: mates in that many
 * moves), `capture` (a capture on the way), `count` (the lines, the run's end) and `lives` (the run
 * closes at that many failures). A position is thought over on a frozen board, up to THINK_SECONDS;
 * « Je réponds » ({@see self::ready()}) starts the answer's clock: PLY_SECONDS per half-move of the
 * solution, on the server's clock; the whole line, both sides, is then judged once
 * ({@see LineJudge}). Late, or not played (`pass`): a failure. Never rated.
 *
 * The Métronome (docs/CALCULATION.md, § 6): config `streak` (the lines right in a row to win, in
 * place of `mate`) and `lives`: mates of 1 to 4 moves, longer on average as lines come right, their
 * length never told; the thinking time shrinks with each line right ({@see CalculationRules::tempo()});
 * a failure breaks the streak and costs a life; won at the streak, lost at the last life. No XP per
 * line (the boss's reward). Its summary's `metrics.failures` is its lives when the streak was not
 * reached, so that the path judges it lost.
 *
 * @phpstan-type SanMove array{uci: string, san: string}
 * @phpstan-type Config array{mate: int|null, capture: bool, count: int|null, lives: int|null, streak: int|null}
 */
final class CalculationModule implements TimeboxedModuleInterface, ReviewableModuleInterface, CountedModuleInterface, ReadyModuleInterface
{
    public const SUBJECT_TYPE = 'calculation_player';
    public const ITEM_TYPE = 'calculation';
    public const SOURCE_TYPE = 'calculation_attempt';

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly AttemptRepository $attempts,
        private readonly MatePicker $picker,
        private readonly PuzzleCatalog $catalog,
        private readonly RatingRepository $ratings,
        private readonly EventPublisher $events,
    ) {
    }

    public function module(): Module
    {
        return Module::Calculation;
    }

    public function subjectType(): string
    {
        return self::SUBJECT_TYPE;
    }

    public function isCounted(array $config): bool
    {
        return isset($config['count']) || isset($config['streak']);
    }

    public function prepare(User $user, array $settings, string $notes): PreparedStep
    {
        throw new InvalidRunConfigException('Calculation is played from the mind map only for now.');
    }

    public function start(Run $run, \DateTimeImmutable $now): void
    {
        if (!$run->getSubjectId()->equals($run->getUser()->getId())) {
            throw new SubjectNotFoundException();
        }
        if (!$run->isPathStep()) {
            throw new InvalidRunConfigException('Calculation is played from the mind map only for now.');
        }
        self::check($run->getConfig());
        // Served now: nothing to draw refuses the run instead of opening an empty one.
        $this->serve($run, $now);
    }

    public function next(Run $run, \DateTimeImmutable $now): Item
    {
        return $this->item($run, $this->attempts->findPendingOfRun($run) ?? $this->serve($run, $now));
    }

    public function ready(Run $run, string $itemId, \DateTimeImmutable $now): array
    {
        $attempt = $this->pendingAttempt($run, $itemId);
        // Past the thinking time, the answer's clock started by itself.
        $attempt->ready(min($now, self::thinkingEnd($attempt)));
        $this->entityManager->flush();

        return ['readyAt' => $attempt->getReadyAt()?->format(\DATE_ATOM)];
    }

    public function submit(Run $run, ItemSubmission $submission, \DateTimeImmutable $now): ItemResult
    {
        $attempt = $this->pendingAttempt($run, $submission->itemId);
        $played = $submission->pass ? [] : $submission->moves;
        if (\count($played) > 2 * CalculationRules::MAX_MATE) {
            throw new InvalidItemSubmissionException('More half-moves than a mate has.');
        }
        if (!$submission->pass && [] === $played) {
            throw new InvalidItemSubmissionException('A line, or pass when the time ran out.');
        }
        $mistakeAt = null;
        if (self::isLate($attempt, $now) || $submission->pass) {
            $status = AttemptStatus::Late;
        } else {
            try {
                $judged = LineJudge::judge($attempt->getFen(), $attempt->getExpected(), $played);
            } catch (IllegalLineException $e) {
                throw new InvalidItemSubmissionException($e->getMessage());
            } catch (InvalidPositionException) {
                throw new \LogicException('A calculation item with an invalid position.');
            }
            $status = $judged['right'] ? AttemptStatus::Right : AttemptStatus::Wrong;
            $mistakeAt = $judged['mistakeAt'];
        }
        $attempt->resolve($status, $played, $mistakeAt, $now);
        $this->entityManager->flush();
        $right = AttemptStatus::Right === $status;

        $this->events->publish(new ExerciseCompleted(
            userId: $run->getUser()->getId()->toRfc4122(),
            type: ExerciseType::Calculation,
            success: $right,
            durationMs: $attempt->getDurationMs() ?? 0,
            itemCount: 1,
            sourceType: self::SOURCE_TYPE,
            sourceId: $attempt->getId()->toRfc4122(),
            occurredAt: $now,
            metadata: [
                'mate' => $attempt->getMate(),
                'status' => $status->value,
                // The Métronome: no XP per line.
                'boss' => null !== self::check($run->getConfig())['streak'],
                'puzzleId' => $this->catalog->get($attempt->getPuzzleId())->getLichessId(),
                'trainingRunId' => $run->getId()->toRfc4122(),
            ],
        ));

        $config = self::check($run->getConfig());
        $stats = $this->attempts->statsOfRun($run);
        $resolved = $stats['right'] + $stats['wrong'] + $stats['late'];
        $failures = $stats['wrong'] + $stats['late'];
        $closes = match (true) {
            null !== $config['streak'] && $stats['streak'] >= $config['streak'] => 'streak_done',
            null !== $config['lives'] && $failures >= $config['lives'] => 'lives_out',
            null !== $config['count'] && $resolved >= $config['count'] => 'count_done',
            default => null,
        };

        return new ItemResult(
            itemId: $attempt->getId()->toRfc4122(),
            success: $right,
            data: [
                'status' => $status->value,
                'expected' => self::sans($attempt->getFen(), $attempt->getExpected()),
                'answer' => self::sans($attempt->getFen(), $played),
                'mistakeAt' => $attempt->getMistakeAt(),
                'position' => $resolved,
                'count' => $config['count'],
                'lives' => null === $config['lives'] ? null : max(0, $config['lives'] - $failures),
                'streak' => $stats['streak'],
                'streakTarget' => $config['streak'],
                'mate' => $attempt->getMate(),
                'durationMs' => $attempt->getDurationMs(),
            ],
            closes: null === $closes ? null : CloseReason::SubjectFinished,
            context: null === $closes ? [] : ['reason' => $closes],
        );
    }

    public function close(Run $run, CloseReason $reason, \DateTimeImmutable $now): void
    {
        // The line on screen at the end is not counted.
        $pending = $this->attempts->findPendingOfRun($run);
        if (null !== $pending) {
            $this->entityManager->remove($pending);
        }
    }

    public function summarize(Run $run, \DateTimeImmutable $closedAt): Summary
    {
        $stats = $this->attempts->statsOfRun($run);
        $played = $stats['right'] + $stats['wrong'] + $stats['late'];
        $mate = $run->getConfig()['mate'] ?? null;
        $count = $run->getConfig()['count'] ?? null;
        $lives = $run->getConfig()['lives'] ?? null;
        $streak = $run->getConfig()['streak'] ?? null;
        $failures = $stats['wrong'] + $stats['late'];
        $streakMetrics = [];
        if (\is_int($streak)) {
            // Not reached (stopped before): as many failures as lives, a defeat for the path.
            $won = $stats['bestStreak'] >= $streak;
            $streakMetrics = [
                'streak' => $streak,
                'bestStreak' => $stats['bestStreak'],
                'won' => $won,
                'failures' => $won ? $failures : max($failures, \is_int($lives) ? $lives : 1),
            ];
        }

        return new Summary(
            durationMs: max(0, (int) round(((float) $closedAt->format('U.u') - (float) $run->getStartedAt()->format('U.u')) * 1000)),
            itemCount: $played,
            successCount: $stats['right'],
            metrics: [
                'mate' => \is_int($mate) ? $mate : null,
                'count' => \is_int($count) ? $count : null,
                'lives' => \is_int($lives) ? $lives : null,
                'right' => $stats['right'],
                'wrong' => $stats['wrong'],
                'late' => $stats['late'],
                'activeMs' => $stats['activeMs'],
                'averageMs' => $played > 0 ? intdiv($stats['activeMs'], $played) : null,
            ] + $streakMetrics,
        );
    }

    public function review(Run $run): array
    {
        return array_map(static fn (Attempt $attempt): ReviewItem => new ReviewItem(
            self::ITEM_TYPE,
            AttemptStatus::Right === $attempt->getStatus() ? ReviewItem::OK : ReviewItem::FAIL,
            $attempt->getDurationMs(),
            [
                'mate' => $attempt->getMate(),
                'status' => $attempt->getStatus()->value,
                'fen' => $attempt->getFen(),
                'expected' => self::sans($attempt->getFen(), $attempt->getExpected()),
                'answer' => self::sans($attempt->getFen(), $attempt->getAnswer() ?? []),
                'mistakeAt' => $attempt->getMistakeAt(),
            ],
        ), $this->attempts->findResolvedOfRun($run));
    }

    /**
     * @throws ItemNotFoundException
     * @throws ItemAlreadySubmittedException
     */
    private function pendingAttempt(Run $run, string $itemId): Attempt
    {
        $attempt = Uuid::isValid($itemId) ? $this->attempts->findOwned(Uuid::fromString($itemId), $run->getUser()) : null;
        if (null === $attempt || !$attempt->getRun()->getId()->equals($run->getId())) {
            throw new ItemNotFoundException();
        }
        if (!$attempt->isPending()) {
            throw new ItemAlreadySubmittedException();
        }

        return $attempt;
    }

    /**
     * A new line for the run, below the player's puzzle rating: of the lesson's mate, or for the
     * Métronome one of its mates for the lines right so far (a shorter one when none is left), at
     * its tempo.
     *
     * @throws SubjectUnavailableException no mate fits
     */
    private function serve(Run $run, \DateTimeImmutable $now): Attempt
    {
        $config = self::check($run->getConfig());
        $rating = $this->ratings->findOneByUser($run->getUser())?->getRating() ?? RatingCalculator::DEFAULT_RATING;
        $target = max(0, (int) round($rating) - CalculationRules::RATING_GAP);
        $think = CalculationRules::THINK_SECONDS;
        if (null === $config['mate']) {
            $right = $this->attempts->statsOfRun($run)['right'];
            $think = CalculationRules::tempo($right);
            $mates = CalculationRules::streakMates($right);
            $first = $mates[random_int(0, \count($mates) - 1)];
            $line = null;
            foreach ([$first, ...($first > 1 ? range($first - 1, 1) : []), ...($first < CalculationRules::MAX_MATE ? range($first + 1, CalculationRules::MAX_MATE) : [])] as $mate) {
                $line = $this->picker->pick($run, $mate, false, $target);
                if (null !== $line) {
                    break;
                }
            }
        } else {
            $line = $this->picker->pick($run, $config['mate'], $config['capture'], $target);
        }
        if (null === $line) {
            throw new SubjectUnavailableException(CloseReason::SubjectUnavailable, ['reason' => 'no_puzzle'], 'No mate to draw.');
        }
        $attempt = new Attempt($run, $line->puzzleId, $line->rating, $line->mate, $line->fen, $line->expected, $think, $now);
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();

        return $attempt;
    }

    private function item(Run $run, Attempt $attempt): Item
    {
        $config = self::check($run->getConfig());
        $stats = $this->attempts->statsOfRun($run);
        $fen = $attempt->getFen();

        return new Item($attempt->getId()->toRfc4122(), self::ITEM_TYPE, [
            'fen' => $fen,
            'color' => 'b' === (explode(' ', $fen)[1] ?? 'w') ? 'black' : 'white',
            // The lesson's level (the line's length is no secret there); the Métronome hides it.
            'mate' => null === $config['streak'] ? $attempt->getMate() : null,
            'capture' => $config['capture'],
            'thinkSeconds' => $attempt->getThinkSeconds(),
            'streak' => $stats['streak'],
            'streakTarget' => $config['streak'],
            'right' => $stats['right'],
            'plySeconds' => CalculationRules::PLY_SECONDS,
            'position' => $stats['right'] + $stats['wrong'] + $stats['late'] + 1,
            'count' => $config['count'],
            'lives' => null === $config['lives'] ? null : max(0, $config['lives'] - $stats['wrong'] - $stats['late']),
            'startedAt' => $attempt->getStartedAt()->format(\DATE_ATOM),
            'readyAt' => $attempt->getReadyAt()?->format(\DATE_ATOM),
        ]);
    }

    private static function thinkingEnd(Attempt $attempt): \DateTimeImmutable
    {
        return $attempt->getStartedAt()->modify(\sprintf('+%d seconds', $attempt->getThinkSeconds()));
    }

    /**
     * Whether a line comes after its time, on the server's clock: PLY_SECONDS per half-move of the
     * solution from « Je réponds » (or from the end of the thinking time, without it). The instants
     * are stored to the second (truncated): one more second, and the network's tolerance, always in
     * the player's favour.
     */
    private static function isLate(Attempt $attempt, \DateTimeImmutable $at): bool
    {
        $readyAt = min($attempt->getReadyAt() ?? self::thinkingEnd($attempt), self::thinkingEnd($attempt));
        $deadlineMs = ((int) $readyAt->format('U') + 1 + CalculationRules::PLY_SECONDS * \count($attempt->getExpected())) * 1000 + TimeboxRunner::SUBMISSION_TOLERANCE_MS;

        return (int) round((float) $at->format('U.u') * 1000) > $deadlineMs;
    }

    /**
     * Moves replayed from a position with their SAN (English letters); stops at the first one that
     * does not replay.
     *
     * @param list<string> $ucis
     *
     * @return list<SanMove>
     */
    private static function sans(string $fen, array $ucis): array
    {
        try {
            $rules = Rules::fromFen($fen);
        } catch (InvalidPositionException) {
            return [];
        }
        $moves = [];
        foreach ($ucis as $uci) {
            $move = $rules->playUci($uci);
            if (null === $move) {
                break;
            }
            $moves[] = ['uci' => $uci, 'san' => $move->san ?? $uci];
        }

        return $moves;
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
        if ([] !== array_diff(array_keys($config), ['mate', 'capture', 'count', 'lives', 'streak'])) {
            throw new InvalidRunConfigException('Unknown option: only mate, capture, count, lives and streak are accepted.');
        }
        $streak = self::limit($config, 'streak', CalculationRules::MAX_STREAK);
        $mate = $config['mate'] ?? null;
        if (null !== $streak) {
            // The Métronome: its own mates, its lives.
            if (null !== $mate || isset($config['capture']) || isset($config['count']) || !isset($config['lives'])) {
                throw new InvalidRunConfigException('streak: with lives, without mate, capture nor count.');
            }
        } elseif (!\is_int($mate) || $mate < 1 || $mate > CalculationRules::MAX_MATE) {
            throw new InvalidRunConfigException(\sprintf('mate must be a whole number from 1 to %d.', CalculationRules::MAX_MATE));
        }
        $capture = $config['capture'] ?? false;
        if (!\is_bool($capture)) {
            throw new InvalidRunConfigException('capture must be true or false.');
        }

        return [
            'mate' => $mate,
            'capture' => $capture,
            'count' => self::limit($config, 'count', CalculationRules::MAX_COUNT),
            'lives' => self::limit($config, 'lives', CalculationRules::MAX_LIVES),
            'streak' => $streak,
        ];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @throws InvalidRunConfigException
     */
    private static function limit(array $config, string $key, int $max): ?int
    {
        $value = $config[$key] ?? null;
        if (null !== $value && (!\is_int($value) || $value < 1 || $value > $max)) {
            throw new InvalidRunConfigException(\sprintf('%s must be a whole number from 1 to %d.', $key, $max));
        }

        return $value;
    }
}
