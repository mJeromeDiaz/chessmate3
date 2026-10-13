<?php

declare(strict_types=1);

namespace App\Enum\PuzzleStreak;

/**
 * How the difficulty of a puzzle streak climbs (docs/PUZZLE_STREAK.md,
 * {@see \App\PuzzleStreak\StreakRules}). Stored as its value: add cases, never rename one.
 */
enum Ramp: string
{
    /** A target rating rising a little after each puzzle solved. */
    case Ladder = 'ladder';
    /** A few puzzles per rating tier, then the next tier. */
    case Tiers = 'tiers';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $ramp): string => $ramp->value, self::cases());
    }
}
