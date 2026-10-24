<?php

declare(strict_types=1);

namespace App\ApiResource\MindMap;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\MindMap\PathProvider;

/**
 * The player's path on a capacity of the mind map (docs/MIND_MAP.md, § 7): chapters, sections,
 * steps and their status, badges earned, chests not opened yet.
 *
 * @phpstan-import-type PathView from \App\MindMap\Progress\PathReader
 * @phpstan-import-type ChapterView from \App\MindMap\Progress\PathReader
 * @phpstan-import-type ChestView from \App\MindMap\Progress\PathReader
 */
#[ApiResource(
    shortName: 'MindMapPath',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/mind-map/{capacity}',
            requirements: ['capacity' => self::CAPACITY_PATTERN],
            openapi: new Operation(summary: 'The path of a capacity: chapters, sections, steps and where the player stands.'),
            provider: PathProvider::class,
        ),
    ],
)]
final class Path
{
    /** The capacities with a path ({@see \App\Enum\MindMap\Capacity}): never « runs » nor « chests ». */
    public const CAPACITY_PATTERN = 'visualisation|calculation|endgames';
    /** A step's key ({@see \App\MindMap\Path\Step::$key}). */
    public const STEP_PATTERN = '[0-9a-z][0-9a-z-]{0,23}';

    #[ApiProperty(identifier: true)]
    public string $capacity;

    /** @var list<ChapterView> */
    public array $chapters = [];

    /** @var list<array{badge: string, step: string, earnedAt: string}> */
    public array $badges = [];

    /** @var list<ChestView> */
    public array $unopenedChests = [];

    /**
     * @param PathView $view
     */
    public static function of(array $view): self
    {
        $path = new self();
        $path->capacity = $view['capacity'];
        $path->chapters = $view['chapters'];
        $path->badges = $view['badges'];
        $path->unopenedChests = $view['unopenedChests'];

        return $path;
    }
}
