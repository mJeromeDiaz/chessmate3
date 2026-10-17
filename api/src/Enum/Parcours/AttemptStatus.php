<?php

declare(strict_types=1);

namespace App\Enum\Parcours;

/**
 * A puzzle of a parcours (docs/PARCOURS.md): served, then solved or failed (a hint or the solution
 * is a miss). Stored as its value: add cases, never rename one.
 */
enum AttemptStatus: string
{
    case Pending = 'pending';
    case Solved = 'solved';
    case Failed = 'failed';
}
