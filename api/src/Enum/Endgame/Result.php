<?php

declare(strict_types=1);

namespace App\Enum\Endgame;

/**
 * The result of a position with perfect play, for the side to move (docs/ENDGAMES.md).
 */
enum Result: string
{
    case Win = 'win';
    case Draw = 'draw';
    case Loss = 'loss';

    /** The same position seen by the other side. */
    public function flip(): self
    {
        return match ($this) {
            self::Win => self::Loss,
            self::Draw => self::Draw,
            self::Loss => self::Win,
        };
    }
}
