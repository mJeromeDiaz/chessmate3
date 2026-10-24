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

    /** As good as `$wanted` or better for the same side: a win keeps a draw to hold. */
    public function atLeast(self $wanted): bool
    {
        $rank = static fn (self $result): int => match ($result) {
            self::Win => 2,
            self::Draw => 1,
            self::Loss => 0,
        };

        return $rank($this) >= $rank($wanted);
    }

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
