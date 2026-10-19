<?php

declare(strict_types=1);

namespace App\Enum\PuzzleRush;

/**
 * Where a puzzle of a rush stands (docs/PUZZLE_RUSH.md). Stored as its value: add cases, never
 * rename one.
 */
enum AttemptStatus: string
{
    /** Served, not submitted yet. */
    case Pending = 'pending';
    case Solved = 'solved';
    /** A wrong move, a hint, the solution or a skip: no second chance, the next puzzle comes. */
    case Failed = 'failed';
}
