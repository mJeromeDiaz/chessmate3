<?php

declare(strict_types=1);

namespace App\Enum\MindMap;

/**
 * Where the player stands on a step (docs/MIND_MAP.md, § 3): computed, never stored.
 */
enum StepStatus: string
{
    /** Not reachable yet. */
    case Locked = 'locked';
    /** Playable now (an exercise may already have some of its passes). */
    case Open = 'open';
    /** Reachable, but its module does not exist yet: an exercise may be skipped, a boss blocks. */
    case Soon = 'soon';
    /** A hidden boss whose section is not cleared yet: shown as « ? ». */
    case Hidden = 'hidden';
    /** All its passes done, its boss beaten, its chest earned. */
    case Done = 'done';
    /** An exercise skipped (and not passed since): it opens what follows, it earns nothing. */
    case Skipped = 'skipped';
}
