<?php

declare(strict_types=1);

namespace App\ApiResource\Brilliancy;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Brilliancy\OverviewProvider;

/**
 * The signed-in user's « Brillant ou Blunder » (docs/BRILLIANCY.md): the run lengths, the index's
 * size by kind, and the results so far.
 */
#[ApiResource(
    shortName: 'BrilliancyOverview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/brilliancy', provider: OverviewProvider::class),
    ],
)]
final class Overview
{
    /** @var array{runMinutes: list<int>, defaultMinutes: int} */
    public array $rules;

    /** @var array{brilliant: int, blunder: int} positions indexed by kind */
    public array $positions = ['brilliant' => 0, 'blunder' => 0];

    /** @var array{played: int, right: int, wrong: int, brilliant: array{played: int, right: int}, blunder: array{played: int, right: int}} */
    public array $results;
}
