<?php

declare(strict_types=1);

namespace App\Enum\Shop;

/**
 * What a crown buys (docs/SHOP.md): each effect is coded, offers only combine them. Stored as its
 * value: add cases, never rename one.
 */
enum ItemType: string
{
    /** Daily streak freezes: they cover missed days, automatically. */
    case StreakFreeze1 = 'streak_freeze_1';
    case StreakFreeze2 = 'streak_freeze_2';
    case StreakFreeze7 = 'streak_freeze_7';
    case StreakFreeze14 = 'streak_freeze_14';
    /** Puzzle streak: the solution shown, the streak kept, an equivalent puzzle instead. */
    case PuzzleStreakPass = 'puzzle_streak_pass';
    /** Puzzle streak: the piece to move highlighted, the streak kept. */
    case PuzzleStreakHelp = 'puzzle_streak_help';
    /** A rated puzzle missed: its rating loss cancelled. */
    case PuzzlePardon = 'puzzle_pardon';
    /** A blindfold puzzle: its mistakes erased during play, or missed then solved after all. */
    case BlindfoldPardon = 'blindfold_pardon';
    /** A coordinates series launched with it: its first wrong square not counted. */
    case CoordinatesPardon = 'coordinates_pardon';

    /** Days covered by a freeze; null for the other items. */
    public function freezeDays(): ?int
    {
        return match ($this) {
            self::StreakFreeze1 => 1,
            self::StreakFreeze2 => 2,
            self::StreakFreeze7 => 7,
            self::StreakFreeze14 => 14,
            default => null,
        };
    }

    /** The module a module aid belongs to (the store's tabs); null for the others. */
    public function module(): ?string
    {
        return match ($this) {
            self::PuzzlePardon => 'puzzles',
            self::BlindfoldPardon => 'blindfold',
            self::CoordinatesPardon => 'coordinates',
            default => null,
        };
    }
}
