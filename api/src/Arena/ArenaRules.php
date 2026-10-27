<?php

declare(strict_types=1);

namespace App\Arena;

use App\Enum\Arena\Tier;

/**
 * The arena's rules (docs/ARENA.md), in one place. Pure.
 */
final class ArenaRules
{
    /** The time controls offered: minutes per side + seconds added per move. */
    public const CADENCES = [
        '3+2' => [180, 2],
        '5+3' => [300, 3],
        '10+5' => [600, 5],
        '15+10' => [900, 10],
    ];
    public const DEFAULT_CADENCE = '5+3';

    /** A move received this late after the player's clock ran out still counts (network). */
    public const NETWORK_TOLERANCE_MS = 2_000;

    /** The boss has the move and its move never comes (the tab was closed): lost after this. */
    public const ABANDON_SECONDS = 300;

    /** The first move of each side is free: the clocks start at this ply. */
    public const CLOCK_FROM_PLY = 2;

    /** A game longer than this is refused (no real game is). */
    public const MAX_PLIES = 1_000;

    /** First defeat of a boss in the arena: its crystals, by its rank on its path. */
    public const CRYSTALS = [
        Tier::Hidden->value => 50,
        Tier::Chapter->value => 100,
        Tier::Final->value => 200,
    ];

    /** Badges: [badge, bosses beaten at least once]. */
    public const BADGES = [
        ['arena_first', 1],
        ['arena_ten', 10],
    ];
    /** Every boss of the roster beaten at least once. */
    public const BADGE_ALL = 'arena_all';

    /**
     * @return array{0: int, 1: int}|null base seconds and increment, null for an unknown cadence
     */
    public static function cadence(string $cadence): ?array
    {
        return self::CADENCES[$cadence] ?? null;
    }

    /**
     * @return list<string>
     */
    public static function cadences(): array
    {
        return array_keys(self::CADENCES);
    }

    public static function crystals(Tier $tier): int
    {
        return self::CRYSTALS[$tier->value];
    }
}
