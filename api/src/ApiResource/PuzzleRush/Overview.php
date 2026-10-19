<?php

declare(strict_types=1);

namespace App\ApiResource\PuzzleRush;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\PuzzleRush\OverviewProvider;

/**
 * The signed-in user's puzzle rushes (docs/PUZZLE_RUSH.md): the rules (lengths, weight of a miss,
 * ramp), the record of each length and the last rushes.
 *
 * @phpstan-import-type Record from \App\PuzzleRush\Record\Records
 * @phpstan-import-type Recent from \App\PuzzleRush\Record\Records
 */
#[ApiResource(
    shortName: 'PuzzleRushOverview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/puzzle-rush', provider: OverviewProvider::class),
    ],
)]
final class Overview
{
    /** @var array{budgets: list<int>, defaultBudget: int, errorWeight: int, successAccuracy: float, startBelow: int, startMin: int, step: int, maxThemes: int} */
    public array $rules;

    /** @var list<Record> one per length, shortest first */
    public array $records = [];

    /** @var list<Recent> the latest first */
    public array $recent = [];
}
