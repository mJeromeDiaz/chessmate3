<?php

declare(strict_types=1);

namespace App\ApiResource\Coach;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Repertoire\Repertoire;
use App\Coach\Diagnosis\Diagnosis as DiagnosisRules;
use App\Coach\Session\Composer;
use App\State\Coach\ComposeProcessor;

/**
 * The tailored session composed from a build of the user's games (docs/COACH.md) and saved as
 * their coach plan (planId, replaced each time): its modules in order, each with its minutes,
 * whether it is in the plan (else the reason: no_games, no_puzzles for Woodpecker; not_asked,
 * no_opening, failed for the repertoire) and the facts the page tells about it. 404 unknown build;
 * 409 not_ready; 422 limit_plans.
 *
 * @phpstan-import-type DiagnosisArray from DiagnosisRules
 * @phpstan-import-type ModuleArray from Composer
 */
#[ApiResource(
    shortName: 'CoachSession',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/coach/builds/{id}/session',
            requirements: ['id' => Repertoire::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Composes the tailored session and saves it as the coach plan. 409 not_ready; 422 limit_plans.'),
            input: ComposeInput::class,
            read: false,
            processor: ComposeProcessor::class,
        ),
    ],
)]
final class Composition
{
    public string $planId;
    /** The minutes of the steps in the plan. */
    public int $minutes;
    /** @var DiagnosisArray */
    public array $diagnosis;
    /** @var array{status: string, repertoires: list<array{id: string, name: string, color: string}>} status: existing, generated, not_asked, no_opening, failed */
    public array $repertoire;
    /** @var list<ModuleArray> */
    public array $modules;
}
