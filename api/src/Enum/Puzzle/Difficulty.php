<?php

declare(strict_types=1);

namespace App\Enum\Puzzle;

/**
 * Relative difficulty asked by the player, as an offset on the target puzzle rating.
 */
enum Difficulty: string
{
    case Easier = 'easier';
    case Normal = 'normal';
    case Harder = 'harder';

    public function ratingOffset(): int
    {
        return match ($this) {
            self::Easier => -250,
            self::Normal => 0,
            self::Harder => 250,
        };
    }
}
