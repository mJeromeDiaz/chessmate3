<?php

declare(strict_types=1);

namespace App\Endgame;

use App\Gamification\Xp\XpRules;

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
    /** The slider's step. */
    public const ELO_STEP = 100;
    /** Learn mode: the textbook position, then this many variants, per lesson. */
    public const LEARN_VARIANTS = 2;
    /** Moves allowed beyond perfect play: 50 % more, at least MIN_MARGIN_MOVES. */
    public const MARGIN = 0.5;
    public const MIN_MARGIN_MOVES = 4;
    /** Plies of a game sent at most (both sides): the longest budget twice, and some room. */
    public const MAX_PLIES = 240;
    /** Moves allowed when the tablebase gives no distance to mate (6 and 7 pieces). */
    public const DEFAULT_BUDGET_MOVES = 40;
    /** The Roi des cendres (§ 10): each position's clock, from the first, shortened by each... */
    public const ASHES_FIRST_SECONDS = 90;
    public const ASHES_STEP_SECONDS = 8;
    public const ASHES_MIN_SECONDS = 30;
    /** ...a late submission's network tolerance (no grace beyond it). */
    public const ASHES_TOLERANCE_SECONDS = 2;
    /** Its margins: 25 % (at least 2 moves) up to this position, 10 % (at least 1) after. */
    public const ASHES_TIGHT_FROM = 6;
    /** Its mates: perfect play in 15 moves at most (Fou + Cavalier is out). */
    public const ASHES_MAX_MATE_PLIES = 29;
    /** Its distractions' tiers: from position 4, then from position 7 (lot 4, the front's). */
    private const ASHES_TIERS_FROM = [4, 7];

    /**
     * The rules the free page shows (GET /endgames).
     *
     * @return array{runMinutes: list<int>, defaultMinutes: int, minElo: int, maxElo: int, eloStep: int, xp: array{weak: int, strong: int, perfect: int, failed: int, strongElo: int}}
     */
    public static function toArray(): array
    {
        return [
            'runMinutes' => self::RUN_MINUTES,
            'defaultMinutes' => self::DEFAULT_MINUTES,
            'minElo' => self::MIN_ELO,
            'maxElo' => self::MAX_ELO,
            'eloStep' => self::ELO_STEP,
            'xp' => [
                'weak' => XpRules::ENDGAME_WEAK,
                'strong' => XpRules::ENDGAME_STRONG,
                'perfect' => XpRules::ENDGAME_PERFECT,
                'failed' => XpRules::ENDGAME_FAILED,
                'strongElo' => XpRules::ENDGAME_STRONG_ELO,
            ],
        ];
    }

    /**
     * The Elo the free page proposes for a puzzle rating: rounded to the slider's step, below
     * « Parfait » (chosen, never proposed).
     */
    public static function proposedElo(float $rating): int
    {
        $elo = (int) (round($rating / self::ELO_STEP) * self::ELO_STEP);

        return max(self::MIN_ELO, min(self::MAX_ELO - self::ELO_STEP, $elo));
    }

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

    /**
     * The Roi des cendres: the clock of its $index-th position (1-based), in seconds.
     */
    public static function ashesClock(int $index): int
    {
        return max(self::ASHES_MIN_SECONDS, self::ASHES_FIRST_SECONDS - self::ASHES_STEP_SECONDS * ($index - 1));
    }

    /**
     * The Roi des cendres: the moves allowed at its $index-th position (1-based), tighter than
     * {@see self::budgetMoves()}.
     */
    public static function ashesBudget(?int $dtm, int $index): int
    {
        if (null === $dtm || 0 === $dtm) {
            return self::DEFAULT_BUDGET_MOVES;
        }
        $perfect = intdiv(abs($dtm) + 1, 2);
        [$margin, $min] = $index < self::ASHES_TIGHT_FROM ? [0.25, 2] : [0.10, 1];

        return max($perfect + $min, (int) ceil(round($perfect * (1 + $margin), 6)));
    }

    /**
     * The Roi des cendres: the distractions' tier (1 to 3) of its $index-th position (1-based).
     */
    public static function ashesTier(int $index): int
    {
        return 1 + \count(array_filter(self::ASHES_TIERS_FROM, static fn (int $from): bool => $index >= $from));
    }
}
