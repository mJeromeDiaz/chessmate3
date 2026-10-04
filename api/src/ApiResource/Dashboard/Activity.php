<?php

declare(strict_types=1);

namespace App\ApiResource\Dashboard;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Dashboard\Activity\ActivityCalendar;
use App\State\Dashboard\DashboardProvider;

/**
 * The user's activity heatmap (docs/DASHBOARD.md): exercises per local day over the last `days`
 * days (7 to 371, default 84), and all-time totals per exercise type.
 *
 * @phpstan-import-type Day from ActivityCalendar
 * @phpstan-import-type Total from ActivityCalendar
 */
#[ApiResource(
    shortName: 'DashboardActivity',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/dashboard/activity',
            openapi: new Operation(parameters: [
                new Parameter('days', 'query', 'Number of local days, today included (7 to 371, default 84)', schema: ['type' => 'integer']),
            ]),
            provider: DashboardProvider::class,
        ),
    ],
)]
final class Activity
{
    public const DEFAULT_DAYS = 84;

    /** IANA timezone the local days are counted in. */
    public string $timezone;
    /** First day of the period (Y-m-d, local). */
    public string $from;
    /** Today (Y-m-d, local). */
    public string $today;
    /** @var list<Day> active days only, oldest first */
    public array $days = [];
    /** @var array<string, Total> by exercise type, all time */
    public array $totals = [];
}
