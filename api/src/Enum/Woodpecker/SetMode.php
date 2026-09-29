<?php

declare(strict_types=1);

namespace App\Enum\Woodpecker;

/**
 * How a set progresses (docs/WOODPECKER.md). Stored as its value: add cases, never rename one.
 */
enum SetMode: string
{
    /** Cycles with shrinking deadlines, optional rest between them. */
    case Classic = 'classic';
    /** Timed runs from the first puzzle, the set grows when a run runs out of puzzles. */
    case Light = 'light';
}
