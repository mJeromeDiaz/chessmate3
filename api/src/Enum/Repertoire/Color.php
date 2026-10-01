<?php

declare(strict_types=1);

namespace App\Enum\Repertoire;

/**
 * The side a repertoire is played with (docs/REPERTOIRE.md). Stored as its value: add cases, never
 * rename one.
 */
enum Color: string
{
    case White = 'white';
    case Black = 'black';

    /** The FEN side-to-move letter: 'w' or 'b'. */
    public function turn(): string
    {
        return self::White === $this ? 'w' : 'b';
    }
}
