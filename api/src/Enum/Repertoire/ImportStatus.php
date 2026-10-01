<?php

declare(strict_types=1);

namespace App\Enum\Repertoire;

/**
 * Where a repertoire import stands (docs/REPERTOIRE.md, "Import"). Stored as its value: add
 * cases, never rename one.
 */
enum ImportStatus: string
{
    /** Waiting for a worker to analyse it. */
    case Analyzing = 'analyzing';
    /** Analysed: the preview is available, the user chooses where and how to import. */
    case Analyzed = 'analyzed';
    /** Waiting for a worker to apply it. */
    case Applying = 'applying';
    case Done = 'done';
    case Failed = 'failed';
}
