<?php

declare(strict_types=1);

namespace App\Enum\PuzzleStreak;

/**
 * A paid aid of the puzzle streak (docs/PUZZLE_STREAK.md, "Aides de la boutique"), bought in the
 * store: the help lights up the piece to move, the pass skips the puzzle, the streak going on.
 */
enum Aid: string
{
    case Help = 'help';
    case Pass = 'pass';
}
