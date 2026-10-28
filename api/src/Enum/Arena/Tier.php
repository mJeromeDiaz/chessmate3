<?php

declare(strict_types=1);

namespace App\Enum\Arena;

/**
 * A boss's rank on its path, or a professor (docs/ARENA.md, § 2); its first defeat in the arena pays
 * by its level.
 */
enum Tier: string
{
    /** A hidden boss of a section. */
    case Hidden = 'hidden';
    /** The boss of a chapter. */
    case Chapter = 'chapter';
    /** The last boss of a path. */
    case Final = 'final';
    /** A professor, the arena's last level: unlocked by a feat in their module (§ 2 bis). */
    case Master = 'master';
}
