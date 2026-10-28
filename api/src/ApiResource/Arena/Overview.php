<?php

declare(strict_types=1);

namespace App\ApiResource\Arena;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\Arena\OverviewProvider;
use App\State\Arena\PurchaseProcessor;

/**
 * The signed-in player's arena (docs/ARENA.md, § 4): every boss of the roster (its settings, its
 * level and price, whether it can be challenged, the player's results against it), the time
 * controls, the badges, the player's crystals, the game in progress and the bosses whose arrival
 * was not shown yet. `purchase`: buys one of the arena's own bosses (§ 6 bis), the arena after it
 * (the boss bought waits to be shown).
 *
 * @phpstan-import-type BossView from \App\Arena\Roster\ArenaReader
 * @phpstan-import-type BadgeView from \App\Arena\Roster\ArenaReader
 */
#[ApiResource(
    shortName: 'ArenaOverview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/arena', provider: OverviewProvider::class),
        new Post(
            uriTemplate: '/arena/bosses/{boss}/purchase',
            requirements: ['boss' => '[a-z][a-z-]{0,31}'],
            status: 200,
            openapi: new Operation(summary: 'Buys one of the arena\'s own bosses with crystals.'),
            input: false,
            read: false,
            deserialize: false,
            name: 'arena_boss_purchase',
            processor: PurchaseProcessor::class,
        ),
    ],
)]
final class Overview
{
    /** @var list<BossView> */
    public array $bosses = [];

    /** @var list<string> */
    public array $cadences = [];

    public string $defaultCadence;

    /** @var list<BadgeView> */
    public array $badges = [];

    /** The game in progress, null without one. */
    public ?string $currentGame = null;

    /** The player's crystals, for the bosses sold. */
    public int $balance = 0;

    /** @var list<string> the bosses whose arrival was not shown yet (GET /arena/unlocks) */
    public array $pendingUnlocks = [];
}
