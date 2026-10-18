<?php

declare(strict_types=1);

namespace App\Enum\Repertoire;

/**
 * Where a pending position stands (docs/REPERTOIRE.md, "En suspens"). Stored as its value.
 */
enum PendingStatus: string
{
    /** Waiting for the user's move. */
    case Open = 'open';
    /** The user answered it: the position has its prepared move. */
    case Answered = 'answered';
    /** Set aside by the user (restorable). */
    case Dismissed = 'dismissed';
}
