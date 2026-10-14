<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\State\Training\HelpProcessor;

/**
 * An aid of the store spent on an item of a run: a help on the puzzle streak's item on screen
 * (docs/PUZZLE_STREAK.md, `data`: `helps` spent on the item, `aids` left), or a pardon on a
 * blindfold puzzle (docs/BLINDFOLD.md, `data`: `pardon`, `status`, `xp`, `pardons` left).
 */
#[ApiResource(
    shortName: 'TrainingItemHelp',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Post(
            uriTemplate: '/training/runs/{id}/help',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Spends a help of the store on the item on screen (409 with the reason when refused).'),
            input: HelpInput::class,
            read: false,
            processor: HelpProcessor::class,
            name: 'training_run_help',
        ),
        new Post(
            uriTemplate: '/training/runs/{id}/pardon',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Spends a pardon of the store on the item on screen or the one just missed (409 with the reason when refused).'),
            input: HelpInput::class,
            read: false,
            processor: HelpProcessor::class,
            name: HelpProcessor::PARDON,
        ),
        // Item IRI only (answers 404), kept under the domain prefix.
        new NotExposed(uriTemplate: '/training/runs/{id}/help', requirements: ['id' => Set::UUID_PATTERN]),
    ],
)]
final class ItemHelp
{
    /** The run's id. */
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $itemId;
    /** @var array<string, mixed> */
    public array $data;

    /**
     * @param array<string, mixed> $data
     */
    public static function of(string $runId, string $itemId, array $data): self
    {
        $view = new self();
        $view->id = $runId;
        $view->itemId = $itemId;
        $view->data = $data;

        return $view;
    }
}
