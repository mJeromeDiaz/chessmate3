<?php

declare(strict_types=1);

namespace App\Enum\Repertoire;

/**
 * What a repertoire test presents (docs/REPERTOIRE.md): segments (tronçons, the default) or whole
 * lines from the initial position. Stored as its value: add cases, never rename one.
 */
enum TestUnit: string
{
    case Segment = 'segment';
    case Line = 'line';
}
