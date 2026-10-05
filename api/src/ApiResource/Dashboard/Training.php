<?php

declare(strict_types=1);

namespace App\ApiResource\Dashboard;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Dashboard\Training\SessionStats;
use App\Dashboard\Training\TrainingTime;
use App\State\Dashboard\DashboardProvider;

/**
 * The user's training over the last `days` days (docs/DASHBOARD.md): time by local week and by
 * module, totals by module, and the sessions of the period.
 *
 * @phpstan-import-type Week from TrainingTime
 * @phpstan-import-type Total from TrainingTime
 * @phpstan-import-type Sessions from SessionStats
 */
#[ApiResource(
    shortName: 'DashboardTraining',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/dashboard/training',
            openapi: new Operation(parameters: [
                new Parameter('days', 'query', 'Number of local days, today included (7 to 371, default 30)', schema: ['type' => 'integer']),
            ]),
            provider: DashboardProvider::class,
        ),
    ],
)]
final class Training
{
    public const DEFAULT_DAYS = 30;

    /** First day of the period (Y-m-d, local). */
    public string $from;
    /** Today (Y-m-d, local). */
    public string $today;
    /** @var list<Week> every week of the period, from Monday, oldest first */
    public array $weeks = [];
    /** @var array<string, Total> by module, every module listed */
    public array $totals = [];
    /** @var Sessions */
    public array $sessions;
}
