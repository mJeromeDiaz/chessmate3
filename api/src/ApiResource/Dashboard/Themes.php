<?php

declare(strict_types=1);

namespace App\ApiResource\Dashboard;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Dashboard\Puzzle\ThemeStrengths;
use App\State\Dashboard\DashboardProvider;

/**
 * The user's strong and weak puzzle themes over the last `days` days (docs/DASHBOARD.md), rated
 * puzzles only, a success being a puzzle solved without help.
 *
 * @phpstan-import-type ThemeRow from ThemeStrengths
 */
#[ApiResource(
    shortName: 'DashboardThemes',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/dashboard/themes',
            openapi: new Operation(parameters: [
                new Parameter('days', 'query', 'Number of local days, today included (7 to 371, default 30)', schema: ['type' => 'integer']),
            ]),
            provider: DashboardProvider::class,
        ),
    ],
)]
final class Themes
{
    public const DEFAULT_DAYS = 30;

    public string $from;
    public string $today;
    /** Rated puzzles attempted in the period, and solved without help. */
    public int $attempts = 0;
    public int $successCount = 0;
    /** Attempts a theme needs to be listed. */
    public int $minAttempts = ThemeStrengths::MIN_ATTEMPTS;
    /** @var list<ThemeRow> every theme listed, from the best to the worst */
    public array $themes = [];
    /** @var list<ThemeRow> the best first */
    public array $strong = [];
    /** @var list<ThemeRow> the worst first */
    public array $weak = [];
}
