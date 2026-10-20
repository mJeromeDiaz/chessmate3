<?php

declare(strict_types=1);

namespace App\ApiResource\Notation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\Notation\OverviewProvider;

/**
 * The notation module for the signed-in player (docs/NOTATION.md): the rules of the line exercise,
 * the player's repertoires (those fit for it marked) and the legendary games.
 */
#[ApiResource(
    shortName: 'NotationOverview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/notation',
            openapi: new Operation(summary: 'The rules of the line exercise and my repertoires fit for it.'),
            provider: OverviewProvider::class,
        ),
    ],
)]
final class Overview
{
    /** @var array{lineMinSegments: int, lineWritePlies: int} */
    public array $rules;

    /** @var list<array{id: string, name: string, color: string, segments: int, eligible: bool}> */
    public array $repertoires = [];

    /** @var list<array{id: int, white: string, black: string, event: string, year: int|null, plies: int}> the legendary games */
    public array $games = [];
}
