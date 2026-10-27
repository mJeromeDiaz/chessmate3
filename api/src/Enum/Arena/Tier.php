<?php

declare(strict_types=1);

namespace App\Enum\Arena;

/**
 * A boss's rank on its path (docs/ARENA.md): what its first defeat in the arena pays.
 */
enum Tier: string
{
    /** A hidden boss of a section. */
    case Hidden = 'hidden';
    /** The boss of a chapter. */
    case Chapter = 'chapter';
    /** The last boss of a path. */
    case Final = 'final';
}
