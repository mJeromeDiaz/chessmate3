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
}
