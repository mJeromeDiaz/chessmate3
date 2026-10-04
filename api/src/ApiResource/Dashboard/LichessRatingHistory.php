<?php

declare(strict_types=1);

namespace App\ApiResource\Dashboard;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Dashboard\Lichess\RatingHistoryClient;
use App\State\Dashboard\DashboardProvider;

/**
 * The game ratings (blitz, rapid, classical) of the user's linked Lichess account over the last
 * `days` days (7 to 371, default 90). Not linked: `linked` false and no call to Lichess.
 * 503 + X-Lichess-Unavailable when Lichess cannot answer, 429.
 *
 * @phpstan-import-type Perfs from RatingHistoryClient
 */
#[ApiResource(
    shortName: 'DashboardLichessRatingHistory',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/dashboard/lichess-rating-history',
            openapi: new Operation(parameters: [
                new Parameter('days', 'query', 'Number of days, today included (7 to 371, default 90)', schema: ['type' => 'integer']),
            ]),
            provider: DashboardProvider::class,
        ),
    ],
)]
final class LichessRatingHistory
{
    public bool $linked = false;
    /** Lichess id of the linked account. */
    public ?string $username = null;
    public string $from;
    public string $today;
    /** @var Perfs oldest first */
    public array $perfs = ['blitz' => [], 'rapid' => [], 'classical' => []];
}
