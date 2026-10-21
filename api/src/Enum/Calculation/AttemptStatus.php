<?php

declare(strict_types=1);

namespace App\Enum\Calculation;

/**
 * Where a line of the calculation module stands (docs/CALCULATION.md). Stored as its value: add
 * cases, never rename one.
 */
enum AttemptStatus: string
{
    case Pending = 'pending';
    /** The line played is the solution's, defence included (or mates sooner). */
    case Right = 'right';
    case Wrong = 'wrong';
    /** Played after its time, or not played at all: a failure. */
    case Late = 'late';
}
