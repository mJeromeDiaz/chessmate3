<?php

declare(strict_types=1);

namespace App\Enum\Brilliancy;

/**
 * What a sacrifice shown is (docs/BRILLIANCY.md): the puzzle's winning move, or the opponent's move
 * the puzzle punishes. Stored as its value: add cases, never rename one.
 */
enum Kind: string
{
    /** The player's first move of a puzzle: it gives material and wins. */
    case Brilliant = 'brilliant';
    /** The opponent's move that opens a puzzle: it gives material for nothing. */
    case Blunder = 'blunder';

    /**
     * Which of the puzzle's moves is shown: the opponent's first (0) or the player's answer (1).
     * The catalogue's index stores it (`brilliancy_position.ply`).
     */
    public function ply(): int
    {
        return match ($this) {
            self::Blunder => 0,
            self::Brilliant => 1,
        };
    }

    public static function fromPly(int $ply): self
    {
        return 0 === $ply ? self::Blunder : self::Brilliant;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
