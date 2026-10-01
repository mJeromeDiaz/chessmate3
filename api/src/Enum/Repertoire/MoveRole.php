<?php

declare(strict_types=1);

namespace App\Enum\Repertoire;

/**
 * What a move means in its repertoire (docs/REPERTOIRE.md). Stored as its value: add cases, never
 * rename one.
 */
enum MoveRole: string
{
    /**
     * The user's move in a position where the user is to move: exactly one per position (the
     * prepared move, tested), guaranteed by the database.
     */
    case Reference = 'reference';
    /** An opponent's move: any number per position, each one a sub-line. */
    case Reply = 'reply';
}
