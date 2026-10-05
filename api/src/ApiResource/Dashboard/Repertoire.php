<?php

declare(strict_types=1);

namespace App\ApiResource\Dashboard;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Dashboard\Repertoire\RepertoireHealth;
use App\State\Dashboard\DashboardProvider;

/**
 * The health of the user's repertoires (docs/DASHBOARD.md): cards due now, the tests of the last
 * `days` days, the most fragile segments.
 *
 * @phpstan-import-type Fragile from RepertoireHealth
 */
#[ApiResource(
    shortName: 'DashboardRepertoire',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/dashboard/repertoire',
            openapi: new Operation(parameters: [
                new Parameter('days', 'query', 'Number of local days for the tests, today included (7 to 371, default 30)', schema: ['type' => 'integer']),
            ]),
            provider: DashboardProvider::class,
        ),
    ],
)]
final class Repertoire
{
    public const DEFAULT_DAYS = 30;

    public string $from;
    public string $today;
    public int $repertoires = 0;
    /** @var array{total: int, new: int, due: int} active cards, due now */
    public array $cards = ['total' => 0, 'new' => 0, 'due' => 0];
    /** @var array{total: int, succeeded: int, successRate: float|null} tests of the period */
    public array $tests = ['total' => 0, 'succeeded' => 0, 'successRate' => null];
    /** @var list<Fragile> the most fragile first */
    public array $fragile = [];
}
