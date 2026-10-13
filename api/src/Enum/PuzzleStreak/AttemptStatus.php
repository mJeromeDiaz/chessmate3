<?php

declare(strict_types=1);

namespace App\Enum\PuzzleStreak;

/**
 * Where a puzzle of a streak stands (docs/PUZZLE_STREAK.md). Stored as its value: add cases, never
 * rename one.
 */
enum AttemptStatus: string
{
    /** Served, not submitted yet. */
    case Pending = 'pending';
    case Solved = 'solved';
    /** A wrong move, or the solution shown: the streak is broken. */
    case Failed = 'failed';
}
