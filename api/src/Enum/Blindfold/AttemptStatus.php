<?php

declare(strict_types=1);

namespace App\Enum\Blindfold;

/**
 * Where a blindfold puzzle stands (docs/BLINDFOLD.md). Stored as its value: add cases, never
 * rename one.
 */
enum AttemptStatus: string
{
    /** Served, not submitted yet. */
    case Pending = 'pending';
    /** Solved without a mistake. */
    case Solved = 'solved';
    /** Solved after a mistake and a peek. */
    case Helped = 'helped';
    case Failed = 'failed';
}
