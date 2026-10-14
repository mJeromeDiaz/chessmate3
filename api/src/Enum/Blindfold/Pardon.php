<?php

declare(strict_types=1);

namespace App\Enum\Blindfold;

/**
 * When a blindfold puzzle took the store's pardon (docs/BLINDFOLD.md): it covers the whole puzzle
 * either way. Stored as its value: add cases, never rename one.
 */
enum Pardon: string
{
    /** During play (after a wrong move): its mistakes are erased, the puzzle goes on. */
    case Play = 'play';
    /** On the miss sheet, after losing: the puzzle becomes solved, an XP top-up follows. */
    case Loss = 'loss';
}
