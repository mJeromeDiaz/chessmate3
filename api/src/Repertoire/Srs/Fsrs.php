<?php

declare(strict_types=1);

namespace App\Repertoire\Srs;

use App\Enum\Repertoire\CardState;
use App\Enum\Repertoire\Rating;

/**
 * FSRS-6, the spaced-repetition scheduler of the repertoire test (docs/REPERTOIRE.md). A port of
 * py-fsrs 6.3.2 (the reference implementation), checked against vectors it generated
 * (tests/Fixtures/Repertoire/fsrs-vectors.json): same parameters, same learning and relearning
 * steps, same arithmetic (whole elapsed days, Python's round-half-to-even, e raised with pow()).
 *
 * Intervals in review are whole days (at least 1, at most {@see self::MAXIMUM_INTERVAL}); with
 * fuzzing, those of 3 days or more are spread a little at random, as py-fsrs does, so that cards
 * learnt together do not all fall due on the same day.
 */
final readonly class Fsrs
{
    /** The 21 default FSRS-6 weights of py-fsrs 6.3.2. */
    public const DEFAULT_PARAMETERS = [
        0.212, 1.2931, 2.3065, 8.2956, 6.4133, 0.8334, 3.0194, 0.001, 1.8722, 0.1666, 0.796,
        1.4835, 0.0614, 0.2629, 1.6483, 0.6014, 1.8729, 0.5425, 0.0912, 0.0658, 0.1542,
    ];
    public const DESIRED_RETENTION = 0.9;
    /** Seconds. */
    public const LEARNING_STEPS = [60, 600];
    /** Seconds. */
    public const RELEARNING_STEPS = [600];
    /** Days. */
    public const MAXIMUM_INTERVAL = 36500;

    private const STABILITY_MIN = 0.001;
    private const MIN_DIFFICULTY = 1.0;
    private const MAX_DIFFICULTY = 10.0;
    private const DAY = 86400;
    /** [start, end, factor] of py-fsrs' fuzz ranges (days). */
    private const FUZZ_RANGES = [[2.5, 7.0, 0.15], [7.0, 20.0, 0.1], [20.0, \INF, 0.05]];

    private float $decay;
    private float $factor;
    /** @var \Closure(): float in [0, 1) */
    private \Closure $random;

    /**
     * @param list<float>               $parameters
     * @param list<int>                 $learningSteps   seconds
     * @param list<int>                 $relearningSteps seconds
     * @param (\Closure(): float)|null  $random          in [0, 1), for the fuzz
     */
    public function __construct(
        private array $parameters = self::DEFAULT_PARAMETERS,
        private float $desiredRetention = self::DESIRED_RETENTION,
        private array $learningSteps = self::LEARNING_STEPS,
        private array $relearningSteps = self::RELEARNING_STEPS,
        private int $maximumInterval = self::MAXIMUM_INTERVAL,
        private bool $fuzz = true,
        ?\Closure $random = null,
    ) {
        if (21 !== \count($parameters)) {
            throw new \InvalidArgumentException('FSRS-6 has 21 parameters.');
        }
        $this->decay = -$parameters[20];
        $this->factor = 0.9 ** (1 / $this->decay) - 1;
        $this->random = $random ?? static fn (): float => mt_rand() / (mt_getrandmax() + 1);
    }

    /**
     * Probability of recalling the card at that instant (0 before its first review).
     */
    public function retrievability(Card $card, \DateTimeImmutable $at): float
    {
        if (null === $card->lastReview || null === $card->stability) {
            return 0.0;
        }
        $elapsed = max(0, self::wholeDays($card->lastReview, $at));

        return (1 + $this->factor * $elapsed / $card->stability) ** $this->decay;
    }

    public function review(Card $card, Rating $rating, \DateTimeImmutable $at): Card
    {
        $at = $at->setTimezone(new \DateTimeZone('UTC'));
        $sinceLast = null === $card->lastReview ? null : self::wholeDays($card->lastReview, $at);
        $state = $card->state;
        $step = $card->step;

        if (CardState::Review === $state) {
            $stability = self::require($card->stability);
            $difficulty = self::require($card->difficulty);
            $stability = null !== $sinceLast && $sinceLast < 1
                ? $this->shortTermStability($stability, $rating)
                : $this->nextStability($difficulty, $stability, $this->retrievability($card, $at), $rating);
            $difficulty = $this->nextDifficulty($difficulty, $rating);

            if (Rating::Again === $rating && [] !== $this->relearningSteps) {
                $state = CardState::Relearning;
                $step = 0;
                $interval = $this->relearningSteps[0];
            } else {
                $interval = $this->nextIntervalDays($stability) * self::DAY;
            }
        } else {
            // Learning or relearning: the same steps logic, on their own steps.
            $steps = CardState::Learning === $state ? $this->learningSteps : $this->relearningSteps;
            $step ??= 0;
            if (null === $card->stability || null === $card->difficulty) {
                $stability = $this->initialStability($rating);
                $difficulty = self::clampDifficulty($this->initialDifficulty($rating));
            } elseif (null !== $sinceLast && $sinceLast < 1) {
                $stability = $this->shortTermStability($card->stability, $rating);
                $difficulty = $this->nextDifficulty($card->difficulty, $rating);
            } else {
                $stability = $this->nextStability($card->difficulty, $card->stability, $this->retrievability($card, $at), $rating);
                $difficulty = $this->nextDifficulty($card->difficulty, $rating);
            }

            $graduate = [] === $steps
                || ($step >= \count($steps) && Rating::Again !== $rating)
                || Rating::Easy === $rating
                || (Rating::Good === $rating && $step + 1 === \count($steps));
            if ($graduate) {
                $state = CardState::Review;
                $step = null;
                $interval = $this->nextIntervalDays($stability) * self::DAY;
            } elseif (Rating::Again === $rating) {
                $step = 0;
                $interval = $steps[0];
            } elseif (Rating::Hard === $rating) {
                $interval = match (true) {
                    0 === $step && 1 === \count($steps) => (int) ($steps[0] * 1.5),
                    0 === $step => intdiv($steps[0] + $steps[1], 2),
                    default => $steps[$step] ?? throw new \LogicException('Step out of range.'),
                };
            } else {
                ++$step;
                $interval = $steps[$step] ?? throw new \LogicException('Step out of range.');
            }
        }

        if ($this->fuzz && CardState::Review === $state) {
            $interval = $this->fuzzed(intdiv($interval, self::DAY)) * self::DAY;
        }

        return new Card($state, $step, $stability, $difficulty, $at->modify(sprintf('+%d seconds', $interval)), $at);
    }

    private function initialStability(Rating $rating): float
    {
        return max($this->parameters[$rating->value - 1], self::STABILITY_MIN);
    }

    private function initialDifficulty(Rating $rating): float
    {
        return $this->parameters[4] - M_E ** ($this->parameters[5] * ($rating->value - 1)) + 1;
    }

    private function nextIntervalDays(float $stability): int
    {
        $interval = ($stability / $this->factor) * ($this->desiredRetention ** (1 / $this->decay) - 1);

        return min(max(self::roundHalfEven($interval), 1), $this->maximumInterval);
    }

    private function shortTermStability(float $stability, Rating $rating): float
    {
        $increase = M_E ** ($this->parameters[17] * ($rating->value - 3 + $this->parameters[18])) * $stability ** -$this->parameters[19];
        if (Rating::Again !== $rating) {
            $increase = max($increase, 1.0);
        }

        return max($stability * $increase, self::STABILITY_MIN);
    }

    private function nextDifficulty(float $difficulty, Rating $rating): float
    {
        $delta = -($this->parameters[6] * ($rating->value - 3));
        $damped = $difficulty + (10.0 - $difficulty) * $delta / 9.0;
        $reverted = $this->parameters[7] * $this->initialDifficulty(Rating::Easy) + (1 - $this->parameters[7]) * $damped;

        return self::clampDifficulty($reverted);
    }

    private function nextStability(float $difficulty, float $stability, float $retrievability, Rating $rating): float
    {
        $next = Rating::Again === $rating
            ? min(
                $this->parameters[11] * $difficulty ** -$this->parameters[12] * (($stability + 1) ** $this->parameters[13] - 1) * M_E ** ((1 - $retrievability) * $this->parameters[14]),
                $stability / M_E ** ($this->parameters[17] * $this->parameters[18]),
            )
            : $stability * (1
                + M_E ** $this->parameters[8]
                * (11 - $difficulty)
                * $stability ** -$this->parameters[9]
                * (M_E ** ((1 - $retrievability) * $this->parameters[10]) - 1)
                * (Rating::Hard === $rating ? $this->parameters[15] : 1)
                * (Rating::Easy === $rating ? $this->parameters[16] : 1));

        return max($next, self::STABILITY_MIN);
    }

    /**
     * py-fsrs' fuzz: a whole number of days around the interval, 3 days and more only. As in
     * py-fsrs, the draw is rounded, so it may exceed the upper bound by one day.
     */
    private function fuzzed(int $days): int
    {
        if ($days < 2.5) {
            return $days;
        }
        $delta = 1.0;
        foreach (self::FUZZ_RANGES as [$start, $end, $factor]) {
            $delta += $factor * max(min((float) $days, $end) - $start, 0.0);
        }
        $max = min(self::roundHalfEven($days + $delta), $this->maximumInterval);
        $min = min(max(2, self::roundHalfEven($days - $delta)), $max);

        return min(self::roundHalfEven(($this->random)() * ($max - $min + 1) + $min), $this->maximumInterval);
    }

    private static function clampDifficulty(float $difficulty): float
    {
        return min(max($difficulty, self::MIN_DIFFICULTY), self::MAX_DIFFICULTY);
    }

    /** Python's timedelta.days: whole days, rounded down. */
    private static function wholeDays(\DateTimeImmutable $from, \DateTimeImmutable $to): int
    {
        return (int) floor(($to->getTimestamp() - $from->getTimestamp()) / self::DAY);
    }

    /** Python's round(): halves go to the even neighbour. */
    private static function roundHalfEven(float $value): int
    {
        return (int) round($value, 0, \PHP_ROUND_HALF_EVEN);
    }

    private static function require(?float $value): float
    {
        return $value ?? throw new \LogicException('A card in review has a stability and a difficulty.');
    }
}
