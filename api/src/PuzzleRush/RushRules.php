<?php

declare(strict_types=1);

namespace App\PuzzleRush;

/**
 * The rules of the puzzle rush (docs/PUZZLE_RUSH.md, validated 2026-10-18), in one place: change a
 * value here and the API, the front (it reads them from GET /puzzle-rush) and the selection follow.
 * Pure.
 *
 * As many puzzles as possible in a fixed time; precision matters as much as speed: each miss wipes
 * out ERROR_WEIGHT puzzles solved. The puzzles start below the player's puzzle rating and get
 * harder with each one solved (a miss does not raise them); when the range has nothing left, it is
 * widened step by step ({@see self::ranges()}).
 */
final class RushRules
{
    /** The lengths of a rush: 2, 3 or 5 minutes. */
    public const BUDGETS = [120, 180, 300];
    /** The length offered first. */
    public const DEFAULT_BUDGET = 180;
    /** score = solved − ERROR_WEIGHT × failed, never below 0. */
    public const ERROR_WEIGHT = 2;
    /** The run is a success (result screen, chest tier, activity) from this accuracy. */
    public const SUCCESS_ACCURACY = 0.8;

    /** The first puzzle aims this far below the player's rating, START_MIN at least. */
    public const START_BELOW = 500;
    public const START_MIN = 600;
    /** Added to the target for each puzzle solved, up to MAX_TARGET. */
    public const STEP = 30;
    public const MAX_TARGET = 3000;
    /** Half-widths around the target: the usual one, then the widenings. */
    public const MARGINS = [50, 150, 400];

    /** Theme keys combined with OR, as for the puzzles module. */
    public const MAX_THEMES = 10;
    /** A solved puzzle's time counts for at most this much in the average (a tab left open). */
    public const AVERAGE_CAP_MS = 60_000;

    /** Puzzles already played in a rush are left out of a draw while others remain. */
    public const CANDIDATES = 30;
    public const DRAWS = 3;

    public static function score(int $solved, int $failed): int
    {
        return max(0, $solved - self::ERROR_WEIGHT * $failed);
    }

    /**
     * Puzzles solved among those played, rounded to 1 %; null when none was played.
     */
    public static function accuracy(int $solved, int $failed): ?float
    {
        $played = $solved + $failed;

        return $played > 0 ? round($solved / $played, 2) : null;
    }

    /**
     * The target of the first puzzle for a player rated $rating.
     */
    public static function start(float $rating): int
    {
        return min(self::MAX_TARGET, max(self::START_MIN, (int) round($rating) - self::START_BELOW));
    }

    /**
     * The target after $solved puzzles solved, from the run's first target $start.
     */
    public static function target(int $start, int $solved): int
    {
        return min(self::MAX_TARGET, $start + self::STEP * max(0, $solved));
    }

    /**
     * The rating ranges to draw from around $target, bounds included: the usual one first, then
     * wider ones for when it has nothing left.
     *
     * @return non-empty-list<array{int, int}>
     */
    public static function ranges(int $target): array
    {
        return array_map(static fn (int $margin): array => [max(0, $target - $margin), $target + $margin], self::MARGINS);
    }

    /**
     * The rules as the front reads them.
     *
     * @return array{budgets: list<int>, defaultBudget: int, errorWeight: int, successAccuracy: float, startBelow: int, startMin: int, step: int, maxThemes: int}
     */
    public static function toArray(): array
    {
        return [
            'budgets' => self::BUDGETS,
            'defaultBudget' => self::DEFAULT_BUDGET,
            'errorWeight' => self::ERROR_WEIGHT,
            'successAccuracy' => self::SUCCESS_ACCURACY,
            'startBelow' => self::START_BELOW,
            'startMin' => self::START_MIN,
            'step' => self::STEP,
            'maxThemes' => self::MAX_THEMES,
        ];
    }
}
