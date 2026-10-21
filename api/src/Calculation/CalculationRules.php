<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * The calculation module's numbers (docs/CALCULATION.md): a mate calculated on a frozen board,
 * then its whole line played at once, both sides.
 */
final class CalculationRules
{
    /** The longest mate drawn (mateIn1 … mateIn4). */
    public const MAX_MATE = 4;
    /** The thinking time's ceiling: past it, the answer opens by itself. */
    public const THINK_SECONDS = 120;
    /** The mini-boss of chapter 2: solving the puzzle first takes up to this long (docs/CALCULATION.md, § 2 ter). */
    public const SOLVE_SECONDS = 300;
    /** Each half-move played at most this long after the previous one (the first after « Je réponds »). */
    public const PLY_SECONDS = 2;
    /** The positions are drawn this far below the player's puzzle rating (calm lessons). */
    public const RATING_GAP = 150;
    /** A run's items at most, its lives at most. */
    public const MAX_COUNT = 30;
    public const MAX_LIVES = 5;
    /** Draws of candidates per rating range, and candidates per draw. */
    public const DRAWS = 4;
    public const CANDIDATES = 8;
    /** An item's time counted in the active time, at most. */
    public const ACTIVE_TIME_CAP_MS = 300_000;

    /** The Métronome (docs/CALCULATION.md, § 6): its thinking time, 3 s less per line right, 15 s at least. */
    public const TEMPO_START_SECONDS = 40;
    public const TEMPO_STEP_SECONDS = 3;
    public const TEMPO_MIN_SECONDS = 15;
    /** A lesson's themes at most. */
    public const MAX_THEMES = 3;
    /** A streak to reach at most. */
    public const MAX_STREAK = 30;

    /** The Métronome's thinking time once $right lines are right. */
    public static function tempo(int $right): int
    {
        return max(self::TEMPO_MIN_SECONDS, self::TEMPO_START_SECONDS - self::TEMPO_STEP_SECONDS * $right);
    }

    /**
     * The mates the Métronome draws from once $right lines are right: longer and longer on average.
     *
     * @return non-empty-list<int>
     */
    public static function streakMates(int $right): array
    {
        return match (true) {
            $right < 3 => [1, 1, 2],
            $right < 6 => [1, 2, 2, 3],
            $right < 9 => [2, 2, 3, 3],
            default => [2, 3, 3, 4],
        };
    }

    /** The Lichess theme of a solution of $moves moves of the player's (oneMove … veryLong, 4 or more). */
    public static function lengthTheme(int $moves): string
    {
        return match ($moves) {
            1 => 'oneMove',
            2 => 'short',
            3 => 'long',
            default => 'veryLong',
        };
    }

    /** The Lichess theme of a mate in $mate. */
    public static function theme(int $mate): string
    {
        return 'mateIn'.$mate;
    }

    /** The half-moves of a mate in $mate from the player's move: theirs and the defences between. */
    public static function plies(int $mate): int
    {
        return 2 * $mate - 1;
    }
}
