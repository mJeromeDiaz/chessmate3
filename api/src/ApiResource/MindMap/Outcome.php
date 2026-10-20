<?php

declare(strict_types=1);

namespace App\ApiResource\MindMap;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\State\MindMap\OutcomeProvider;

/**
 * The verdict on a run played for a step (docs/MIND_MAP.md, § 4): pending while it goes on,
 * passed or failed once closed; with the step's progress and the chests to open. 404 for a run
 * that was not played for a step of the player.
 *
 * @phpstan-import-type ChestView from \App\MindMap\Progress\PathReader
 */
#[ApiResource(
    shortName: 'MindMapOutcome',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/mind-map/runs/{id}',
            requirements: ['id' => Set::UUID_PATTERN],
            openapi: new Operation(summary: 'The verdict on the run of a step.'),
            provider: OutcomeProvider::class,
        ),
    ],
)]
final class Outcome
{
    /** The run's id. */
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $capacity;
    public string $step;
    /** exercise, mini_boss, hidden_boss or boss */
    public string $kind;
    /** pending, passed or failed */
    public string $outcome;
    public int $itemCount = 0;
    public int $successCount = 0;
    /** What the run had to reach: items, and lives for a boss (null for an exercise). */
    public int $count;
    public ?int $lives = null;
    /** The step's passes now, and its passes required. */
    public int $passes = 0;
    public int $required;
    /** This run completed the step (its rewards then). */
    public bool $completed = false;
    /** The XP bonus of the step, when this run completed it. */
    public int $xp = 0;
    /**
     * The step's chest, when this run completed it.
     *
     * @var ChestView|null
     */
    public ?array $chest = null;
    /** @var list<ChestView> every chest of the path not opened yet (those on the way too) */
    public array $unopenedChests = [];
}
