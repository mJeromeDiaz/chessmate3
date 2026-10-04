<?php

declare(strict_types=1);

namespace App\ApiResource\Dashboard;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Dashboard\Rating\RatingHistory as History;
use App\State\Dashboard\DashboardProvider;

/**
 * The user's puzzle rating (Glicko-2) over the last `days` local days (7 to 371, default 90): one
 * point per day with a change, opened by the rating known when the period starts.
 *
 * @phpstan-import-type Point from History
 */
#[ApiResource(
    shortName: 'DashboardRatingHistory',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/dashboard/rating-history',
            openapi: new Operation(parameters: [
                new Parameter('days', 'query', 'Number of local days, today included (7 to 371, default 90)', schema: ['type' => 'integer']),
            ]),
            provider: DashboardProvider::class,
        ),
    ],
)]
final class RatingHistory
{
    public const DEFAULT_DAYS = 90;

    public string $from;
    public string $today;
    /** @var list<Point> oldest first */
    public array $points = [];
}
