<?php

declare(strict_types=1);

namespace App\Arena;

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

    /** The levels of the arena's page: the highest Elo of each (the last: any). */
    public const LEVELS = [1 => 1100, 2 => 1399, 3 => 1699, 4 => 1999, 5 => \PHP_INT_MAX];

    /** The professors' level, after the last level by Elo (§ 2 bis). */
    public const MASTER_LEVEL = 6;

    /** The professors, in their level's order (§ 2 bis). */
    public const MASTERS = ['lizy', 'albert', 'aaron', 'ray'];
    /** Albert: puzzles solved in a row in a puzzle streak. */
    public const ALBERT_STREAK = 50;
    /** Aaron: lines of one repertoire (more than 50). */
    public const AARON_LINES = 51;
    /** Ray: a free blindfold run of this level, length and display time at most, this long at least. */
    public const RAY_LEVEL = 'hard';
    public const RAY_MIN_LENGTH = 4;
    public const RAY_MAX_VISIBLE_SECONDS = 10;
    public const RAY_MIN_SECONDS = 1_200;
    /** …played to its end, with this many puzzles at least, this share solved without a peek. */
    public const RAY_MIN_PUZZLES = 5;
    public const RAY_MIN_PERCENT = 80;

    /** A boss's opening: its first plies (a boss's $openingElo). */
    public const OPENING_PLIES = 20;

    /** First defeat of a boss in the arena: its crystals, by its level (validated 2026-10-27). */
    public const CRYSTALS = [1 => 100, 2 => 200, 3 => 300, 4 => 450, 5 => 600, self::MASTER_LEVEL => 1000];

    /** The price of a boss sold in the shop, by its level: below what its first defeat pays. */
    public const PRICES = [1 => 75, 2 => 150, 3 => 200, 4 => 300, 5 => 400];

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

    public static function crystals(int $level): int
    {
        return self::CRYSTALS[$level];
    }

    public static function level(int $elo): int
    {
        foreach (self::LEVELS as $level => $highest) {
            if ($elo <= $highest) {
                return $level;
            }
        }

        throw new \LogicException('The last level takes any Elo.');
    }

    public static function price(int $level): int
    {
        return self::PRICES[$level];
    }
}
