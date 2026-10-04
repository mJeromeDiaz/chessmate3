<?php

declare(strict_types=1);

namespace App\Enum\Training;

/**
 * Life of a training session (docs/TRAINING.md). Stored as its value: add cases, never rename one.
 */
enum SessionStatus: string
{
    case Active = 'active';
    /** Every step was played or skipped. */
    case Completed = 'completed';
    /** Ended by the user. */
    case Abandoned = 'abandoned';
    /** Not finished on its local day: closed lazily, remaining steps not played. */
    case Expired = 'expired';
}
