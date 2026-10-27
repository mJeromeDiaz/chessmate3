<?php

declare(strict_types=1);

namespace App\Enum\Arena;

/**
 * Whether the player can challenge a boss in the arena (docs/ARENA.md, § 1): computed from the
 * paths, never stored.
 */
enum BossStatus: string
{
    /** Not met on its path yet: a silhouette. */
    case Locked = 'locked';
    /** Its fights on the paths are not built yet. */
    case Soon = 'soon';
    case Available = 'available';
}
