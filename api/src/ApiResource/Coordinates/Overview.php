<?php

declare(strict_types=1);

namespace App\ApiResource\Coordinates;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Coordinates\OverviewProvider;

/**
 * The signed-in user's coordinates (docs/COORDINATES.md): the rules of a series, each orientation's
 * state (validated, best series) and the latest series.
 *
 * @phpstan-type SeriesView array{id: string, runId: string, orientation: string, answerCount: int, successCount: int, successRate: float|null, validated: bool, startedAt: string, closedAt: string|null}
 * @phpstan-type OrientationView array{orientation: string, validated: bool, validatedAt: string|null, seriesCount: int, best: SeriesView|null}
 */
#[ApiResource(
    shortName: 'CoordinatesOverview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/coordinates', provider: OverviewProvider::class),
    ],
)]
final class Overview
{
    /** @var array{seriesSeconds: int, minAnswers: int, minSuccessRate: float} */
    public array $rules;

    /** @var list<OrientationView> white, then black */
    public array $orientations = [];

    /** @var list<SeriesView> the latest closed series first */
    public array $history = [];
}
