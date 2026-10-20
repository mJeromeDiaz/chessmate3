<?php

declare(strict_types=1);

namespace App\ApiResource\Coordinates;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Coordinates\OverviewProvider;

/**
 * The signed-in user's coordinates (docs/COORDINATES.md): the rules of a series, each orientation's
 * state in the find mode (validated, best series), the other modes' series (§ 2 bis) and the latest
 * series.
 *
 * @phpstan-type SeriesView array{id: string, runId: string, orientation: string, mode: string, answerCount: int, successCount: int, successRate: float|null, validated: bool, pardoned: bool, startedAt: string, closedAt: string|null}
 * @phpstan-type OrientationView array{orientation: string, validated: bool, validatedAt: string|null, seriesCount: int, best: SeriesView|null}
 * @phpstan-type ModeView array{mode: string, seriesCount: int, best: SeriesView|null}
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
    /** @var array{seriesMinutes: list<int>, defaultMinutes: int, minAnswersPerMinute: int, minSuccessRate: float} */
    public array $rules;

    /** @var list<OrientationView> white, then black (the find mode) */
    public array $orientations = [];

    /** @var list<ModeView> name, then color: their series (any orientation) and the best one */
    public array $modes = [];

    /** @var list<SeriesView> the latest closed series first */
    public array $history = [];

    /** The store's coordinates pardons held (the launcher's toggle, docs/COORDINATES.md). */
    public int $pardons = 0;
}
