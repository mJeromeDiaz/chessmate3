<?php

declare(strict_types=1);

namespace App\ApiResource\MindMap;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\State\MindMap\OpenChestProcessor;

/**
 * A chest of a path (docs/MIND_MAP.md, § 6): its crystals were credited when it was earned,
 * opening it only marks it seen. Another player's chest answers 404.
 */
#[ApiResource(
    shortName: 'MindMapChest',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/mind-map/chests/{id}/open',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Marks the chest opened (idempotent), with the balance.'),
            input: false,
            read: false,
            deserialize: false,
            name: 'mind_map_chest_open',
            processor: OpenChestProcessor::class,
        ),
        new NotExposed(uriTemplate: '/mind-map/chests/{id}', requirements: ['id' => Set::UUID_PATTERN]),
    ],
)]
final class Chest
{
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $capacity;
    public string $step;
    public int $crystals;
    public bool $opened;
    /** The player's crystals now. */
    public int $balance;
}
