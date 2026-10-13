<?php

declare(strict_types=1);

namespace App\Enum\PuzzleStreak;

/**
 * Where a puzzle streak stands (docs/PUZZLE_STREAK.md). Stored as its value: add cases, never
 * rename one.
 */
enum StreakStatus: string
{
    /** Puzzles are still being served. */
    case Active = 'active';
    /** A puzzle was missed. */
    case Broken = 'broken';
    /** Over without a miss: the run closed (time up, "Achever", no puzzle left). */
    case Ended = 'ended';
}
