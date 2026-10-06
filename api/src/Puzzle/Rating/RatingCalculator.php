<?php

declare(strict_types=1);

namespace App\Puzzle\Rating;

/**
 * How Don't Stay Rooky applies Glicko-2 to puzzles (docs/PUZZLES.md, "Rating rules"):
 *
 * - each rated attempt is a rating period of one game against the puzzle, whose rating and RD are
 *   fixed (the puzzle's RD still matters: an uncertain puzzle rating moves the player less);
 * - before the game, the deviation grows with the days since the last rated attempt (one period
 *   per day), so a returning player's rating moves faster again;
 * - the deviation is kept within [45, 350] and the volatility under 0.1, like Lichess: without a
 *   floor, the RD of a player with thousands of puzzles would shrink until the rating froze.
 */
final class RatingCalculator
{
    public const DEFAULT_RATING = 1500.0;
    /** Glickman's recommended starting RD: a new player's rating is almost unknown. */
    public const DEFAULT_DEVIATION = 350.0;
    public const DEFAULT_VOLATILITY = 0.06;
    /** Middle of Glickman's 0.3–1.2 range: puzzle skill drifts slowly, so no need for a high τ. */
    public const TAU = 0.5;
    public const MIN_DEVIATION = 45.0;
    public const MAX_DEVIATION = 350.0;
    public const MAX_VOLATILITY = 0.1;
    /**
     * Minimum RD when seeding from the Lichess puzzle rating: the two player pools and puzzle sets
     * differ, so the imported value is a good starting point, not a settled rating.
     */
    public const MIN_IMPORTED_DEVIATION = 150.0;

    private readonly Glicko2 $glicko;

    public function __construct()
    {
        $this->glicko = new Glicko2(self::TAU);
    }

    public static function initial(): RatingState
    {
        return new RatingState(self::DEFAULT_RATING, self::DEFAULT_DEVIATION, self::DEFAULT_VOLATILITY);
    }

    public static function fromLichess(float $rating, float $deviation): RatingState
    {
        return new RatingState(
            $rating,
            min(self::MAX_DEVIATION, max(self::MIN_IMPORTED_DEVIATION, $deviation)),
            self::DEFAULT_VOLATILITY,
        );
    }

    public function afterAttempt(
        RatingState $current,
        ?\DateTimeImmutable $lastRatedAt,
        \DateTimeImmutable $now,
        int $puzzleRating,
        int $puzzleDeviation,
        bool $solved,
    ): RatingState {
        if (null !== $lastRatedAt) {
            $days = max(0, $now->getTimestamp() - $lastRatedAt->getTimestamp()) / 86400;
            $current = $this->bounded($this->glicko->inflate($current, $days));
        }

        return $this->bounded($this->glicko->rate($current, [
            new GameResult($puzzleRating, $puzzleDeviation, $solved ? GameResult::WIN : GameResult::LOSS),
        ]));
    }

    /**
     * Probability that a player with this rating solves a puzzle (used by the selection window).
     */
    public function expectedScore(float $rating, float $puzzleRating, float $puzzleDeviation): float
    {
        return $this->glicko->expectedScore($rating, $puzzleRating, $puzzleDeviation);
    }

    private function bounded(RatingState $state): RatingState
    {
        return new RatingState(
            $state->rating,
            min(self::MAX_DEVIATION, max(self::MIN_DEVIATION, $state->deviation)),
            min(self::MAX_VOLATILITY, $state->volatility),
        );
    }
}
