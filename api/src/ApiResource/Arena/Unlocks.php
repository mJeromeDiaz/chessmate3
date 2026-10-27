<?php

declare(strict_types=1);

namespace App\ApiResource\Arena;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\Arena\UnlocksProcessor;
use App\State\Arena\UnlocksProvider;

/**
 * The bosses that arrived in the player's arena and whose arrival was not shown yet (docs/ARENA.md,
 * § 5), in the roster's order; `announce`: the client showed these (idempotent), the others stay.
 *
 * @phpstan-import-type BossView from \App\Arena\Roster\ArenaReader
 */
#[ApiResource(
    shortName: 'ArenaUnlocks',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/arena/unlocks', provider: UnlocksProvider::class),
        new Post(
            uriTemplate: '/arena/unlocks/announce',
            status: 200,
            openapi: new Operation(summary: 'Marks the arrival of these bosses shown.'),
            input: AnnounceInput::class,
            read: false,
            name: 'arena_unlocks_announce',
            processor: UnlocksProcessor::class,
        ),
    ],
)]
final class Unlocks
{
    /** @var list<BossView> */
    public array $bosses = [];
}
