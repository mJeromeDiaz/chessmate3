<?php

declare(strict_types=1);

namespace App\Enum\Woodpecker;

enum CycleStatus: string
{
    /** Rest period after the previous cycle: not playable before availableAt. */
    case Resting = 'resting';
    case Active = 'active';
    case Completed = 'completed';
    /** Deadline passed before the end: a new run of the same cycle replaces it. */
    case Lost = 'lost';
}
