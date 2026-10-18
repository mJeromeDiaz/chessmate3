<?php

declare(strict_types=1);

namespace App\Enum\Repertoire;

/**
 * Why a position of a repertoire built from the user's games is pending (docs/REPERTOIRE.md, "En
 * suspens"). Stored as its value: add cases, never rename one.
 */
enum PendingReason: string
{
    /** Reached by a popular reply the user never met in their games. */
    case ReplyUncovered = 'reply_uncovered';
    /** Reached by a popular reply the user met, without a move of theirs played often enough there. */
    case NoUserMove = 'no_user_move';
    /** Where a short line the user set aside left the others. */
    case ShortLine = 'short_line';
}
