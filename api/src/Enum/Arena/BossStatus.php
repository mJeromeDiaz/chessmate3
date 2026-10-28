<?php

declare(strict_types=1);

namespace App\Enum\Arena;

/**
 * Whether the player can challenge a boss in the arena (docs/ARENA.md, § 1): computed from the
 * paths and the purchases, never stored.
 */
enum BossStatus: string
{
    /** Not beaten on its path yet: a silhouette. */
    case Locked = 'locked';
    /** Its fights on the paths are not built yet. */
    case Soon = 'soon';
    /** The arena's own boss, sold in the shop and not bought yet. */
    case Shop = 'shop';
    case Available = 'available';
}
