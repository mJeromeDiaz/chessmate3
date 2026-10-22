<?php

declare(strict_types=1);

namespace App\Endgame;

/**
 * The rules of the endgames module (docs/ENDGAMES.md, validated 2026-10-22), in one place. Pure.
 */
final class EndgameRules
{
    /** The lengths a run may last, in minutes... */
    public const RUN_MINUTES = [2, 5, 10, 15];
    /** ...the one offered first. */
    public const DEFAULT_MINUTES = 5;
    /** Maia's Elo; the top of the slider is the tablebase's best defence (« Parfait »). */
    public const MIN_ELO = 800;
    public const MAX_ELO = 2600;
    /** Learn mode: the textbook position, then this many variants, per lesson. */
    public const LEARN_VARIANTS = 2;
    /** Moves allowed beyond perfect play: 50 % more, at least MIN_MARGIN_MOVES. */
    public const MARGIN = 0.5;
    public const MIN_MARGIN_MOVES = 4;
    /** Plies of a game sent at most (both sides): the longest budget twice, and some room. */
    public const MAX_PLIES = 240;
    /** Moves allowed when the tablebase gives no distance to mate (6 and 7 pieces). */
    public const DEFAULT_BUDGET_MOVES = 40;

    /**
     * @return list<int> the lengths a run may last, in seconds
     */
    public static function budgets(): array
    {
        return array_map(static fn (int $minutes): int => $minutes * 60, self::RUN_MINUTES);
    }

    public static function allows(int $seconds): bool
    {
        return \in_array($seconds, self::budgets(), true);
    }

    public static function isPerfect(int $elo): bool
    {
        return $elo >= self::MAX_ELO;
    }

    /**
     * The player's moves allowed to reach the goal, from the distance to mate (plies, the player
     * to move), null when unknown.
     */
    public static function budgetMoves(?int $dtm): int
    {
        if (null === $dtm || 0 === $dtm) {
            return self::DEFAULT_BUDGET_MOVES;
        }
        $perfect = intdiv(abs($dtm) + 1, 2);

        return max($perfect + self::MIN_MARGIN_MOVES, (int) ceil($perfect * (1 + self::MARGIN)));
    }
}
