<?php

declare(strict_types=1);

namespace App\Enum\Blindfold;

/**
 * The difficulty of blindfold puzzles (docs/BLINDFOLD.md): a fixed rating range each
 * ({@see \App\Blindfold\Puzzle\PuzzleRules::LEVELS}). Stored as its value: add cases, never rename one.
 */
enum PuzzleLevel: string
{
    case Easy = 'easy';
    case Medium = 'medium';
    case Hard = 'hard';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $level): string => $level->value, self::cases());
    }
}
