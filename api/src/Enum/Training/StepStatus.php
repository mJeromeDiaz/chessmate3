<?php

declare(strict_types=1);

namespace App\Enum\Training;

/**
 * A step of a training session (docs/TRAINING.md). Stored as its value: add cases, never rename one.
 */
enum StepStatus: string
{
    case Pending = 'pending';
    /** Its timed run is active. */
    case Running = 'running';
    /** Its timed run is closed. */
    case Done = 'done';
    /** Passed by the user (e.g. its subject could not be played). */
    case Skipped = 'skipped';
    /** The session ended before it. */
    case Unplayed = 'unplayed';
}
