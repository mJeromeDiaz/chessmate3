<?php

declare(strict_types=1);

namespace App\ApiResource\MindMap;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Training\Run;
use App\State\MindMap\StepActionProcessor;

/**
 * Playing or skipping a step of a path (docs/MIND_MAP.md, § 3 and 4). `start`: the run of its
 * module, judged when it closes (GET /mind-map/runs/{id}); 409 `step_locked`, `step_soon`, or
 * another run in progress. `skip`: an exercise reachable now (409 `step_not_skippable` for a boss
 * or a chest), the path after it.
 */
#[ApiResource(
    shortName: 'MindMapStepAction',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/mind-map/{capacity}/steps/{key}/start',
            requirements: ['capacity' => Path::CAPACITY_PATTERN, 'key' => Path::STEP_PATTERN],
            status: 201,
            openapi: new Operation(summary: 'Starts the run of a step.'),
            input: false,
            read: false,
            deserialize: false,
            name: 'mind_map_step_start',
            processor: StepActionProcessor::class,
        ),
        new Post(
            uriTemplate: '/mind-map/{capacity}/steps/{key}/skip',
            requirements: ['capacity' => Path::CAPACITY_PATTERN, 'key' => Path::STEP_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Skips an exercise: what follows opens, nothing is earned.'),
            input: false,
            read: false,
            deserialize: false,
            name: 'mind_map_step_skip',
            processor: StepActionProcessor::class,
        ),
        // The items' IRI only.
        new NotExposed(
            uriTemplate: '/mind-map/{capacity}/steps/{key}',
            requirements: ['capacity' => Path::CAPACITY_PATTERN, 'key' => Path::STEP_PATTERN],
        ),
    ],
)]
final class StepAction
{
    #[ApiProperty(identifier: true)]
    public string $capacity;

    #[ApiProperty(identifier: true)]
    public string $key;

    /** The run started (start). */
    #[ApiProperty(readableLink: true, genId: false)]
    public ?Run $run = null;

    /** The path after the skip (skip). */
    #[ApiProperty(readableLink: true, genId: false)]
    public ?Path $path = null;
}
