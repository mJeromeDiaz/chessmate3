<?php

declare(strict_types=1);

namespace App\ApiResource\Arena;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Arena\OverviewProvider;

/**
 * The signed-in player's arena (docs/ARENA.md, § 4): every boss of the roster (its settings,
 * whether it can be challenged, the player's results against it), the time controls, the badges,
 * the game in progress and the bosses whose arrival was not shown yet.
 *
 * @phpstan-import-type BossView from \App\Arena\Roster\ArenaReader
 * @phpstan-import-type BadgeView from \App\Arena\Roster\ArenaReader
 */
#[ApiResource(
    shortName: 'ArenaOverview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/arena', provider: OverviewProvider::class),
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

    /** @var list<string> the bosses whose arrival was not shown yet (GET /arena/unlocks) */
    public array $pendingUnlocks = [];
}
