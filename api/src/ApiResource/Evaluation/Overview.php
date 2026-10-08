<?php

declare(strict_types=1);

namespace App\ApiResource\Evaluation;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Evaluation\OverviewProvider;

/**
 * The signed-in user's position evaluation (docs/EVALUATION.md): the settings' bounds, the
 * catalogue's size by side to move, and the results so far.
 */
#[ApiResource(
    shortName: 'EvaluationOverview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/evaluation', provider: OverviewProvider::class),
    ],
)]
final class Overview
{
    /** @var array{minCount: int, maxCount: int, seconds: list<int>, minElo: int, maxElo: int, advantageCp: int, winningCp: int} */
    public array $rules;

    /** @var array{white: int, black: int} active positions by side to move */
    public array $positions = ['white' => 0, 'black' => 0];

    /** @var array{played: int, exact: int, close: int, miss: int, timeout: int, planOk: int} */
    public array $results;
}
