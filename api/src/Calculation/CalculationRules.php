<?php

declare(strict_types=1);

namespace App\Calculation;

/**
 * The calculation module's numbers (docs/CALCULATION.md): a mate calculated on a frozen board,
 * then its whole line played at once, the opponent's replies played by themselves (since
 * 2026-10-28; both sides in the Avocat du diable and the mini-boss's replay from memory).
 */
final class CalculationRules
{
    /** The longest mate drawn (mateIn1 … mateIn4). */
    public const MAX_MATE = 4;
    /** The thinking time's ceiling: past it, the answer opens by itself. */
    public const THINK_SECONDS = 120;
    /** The mini-boss of chapter 2: solving the puzzle first takes up to this long (docs/CALCULATION.md, § 2 ter). */
    public const SOLVE_SECONDS = 300;
    /**
     * Each move of the player at most this long after the opponent's reply (the first after « Je
     * réponds »); each half-move, both sides played by the player.
     */
    public const PLY_SECONDS = 3;
    /** Each reply played by itself: its time on the server's clock (shown, or found by the engine in chapter 3). */
    public const REPLY_SECONDS = 1;
    /** The positions are drawn this far below the player's puzzle rating (calm lessons). */
    public const RATING_GAP = 150;
    /** A run's items at most, its lives at most. */
    public const MAX_COUNT = 30;
    public const MAX_LIVES = 5;
    /** Draws of candidates per rating range, and candidates per draw. */
    public const DRAWS = 4;
    public const CANDIDATES = 8;
    /** Candidates per draw for a kind that few puzzles fit (two legal replies, a mate threatened, forcing moves). */
    public const CANDIDATES_FILTERED = 30;
    /**
     * Chapter 3, judged by the engine in the browser (docs/CALCULATION.md, § 2 quater): a move is one
     * of the best within this many centipawns of the first; the wrong reply offered in 3.1, and the
     * wrong combinations of the mini-boss, at least this much worse.
     */
    public const ENGINE_TOLERANCE_CP = 50;
    public const DECOY_GAP_CP = 150;
    /** 3.2: the opponent's legal replies to the player's first move. */
    public const TWO_REPLIES = 2;
    /** The mini-boss of chapter 3: the forcing moves offered, the solution's first move among them. */
    public const COMBOS = 3;
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

    /**
     * The time to play a line of $plies half-moves (the player's move first) from « Je réponds »:
     * PLY_SECONDS per move of the player and REPLY_SECONDS per reply played by itself, or
     * PLY_SECONDS per half-move when the player plays both sides.
     */
    public static function answerSeconds(int $plies, bool $autoReplies): int
    {
        if (!$autoReplies) {
            return self::PLY_SECONDS * $plies;
        }

        return self::PLY_SECONDS * intdiv($plies + 1, 2) + self::REPLY_SECONDS * intdiv($plies, 2);
    }

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
