<?php

declare(strict_types=1);

namespace App\Enum\Gamification;

/**
 * What an XP gain rewards (docs/GAMIFICATION.md). Stored as its value: add cases, never rename one.
 */
enum XpKind: string
{
    /** An exercise of the activity log, by its result; capped per local day. */
    case Exercise = 'exercise';
    /** A session completed to its end. */
    case Session = 'session';
    /** A Woodpecker cycle completed in time. */
    case Cycle = 'cycle';
    /** A Woodpecker set completed. */
    case Set = 'set';
    /** A weekly quest completed. */
    case Quest = 'quest';
    /** An orientation of the coordinates series validated for the first time. */
    case Validation = 'validation';
    /** A puzzle streak record beaten (docs/PUZZLE_STREAK.md). */
    case Record = 'record';
    /** A blindfold puzzle missed then pardoned with the store's pardon: the top-up to a solved one (docs/BLINDFOLD.md). */
    case Pardon = 'pardon';
}
