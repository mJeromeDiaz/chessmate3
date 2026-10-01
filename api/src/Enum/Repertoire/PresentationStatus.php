<?php

declare(strict_types=1);

namespace App\Enum\Repertoire;

/**
 * Outcome of the presentation of a segment in a repertoire test (docs/REPERTOIRE.md). Stored as
 * its value: add cases, never rename one.
 */
enum PresentationStatus: string
{
    case InProgress = 'in_progress';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    /** Cut by the end of the run before any mistake, or dropped because the repertoire changed: not counted. */
    case Interrupted = 'interrupted';
}
