<?php

declare(strict_types=1);

namespace App\Enum\Repertoire;

/**
 * Where a repertoire built from the user's Lichess games stands (docs/REPERTOIRE.md, "Repertoire
 * from my games"). Stored as its value: add cases, never rename one.
 */
enum AutoBuildStatus: string
{
    /** Games are being downloaded, a batch per request. */
    case Fetching = 'fetching';
    /** Every game is in: the user picks openings, moves and lines. */
    case Ready = 'ready';
    /** The repertoires were created. */
    case Applied = 'applied';
}
