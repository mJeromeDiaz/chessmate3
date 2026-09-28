<?php

declare(strict_types=1);

namespace App\Enum\Puzzle;

enum AttemptStatus: string
{
    /** Puzzle handed out, result not submitted yet. */
    case Pending = 'pending';
    case Solved = 'solved';
    case Failed = 'failed';
}
