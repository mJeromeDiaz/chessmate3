<?php

declare(strict_types=1);

namespace App\ApiResource\Woodpecker;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\NotExposed;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\Entity\Catalog\Puzzle;
use App\State\Woodpecker\ReplacePuzzleProcessor;
use App\State\Woodpecker\SetPuzzleProvider;

/**
 * A puzzle of a set's list, with how it went in the cycles so far. Replaceable at any time while
 * the set is ongoing: another puzzle of the same profile takes its position.
 */
#[ApiResource(
    shortName: 'WoodpeckerSetPuzzle',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/woodpecker/sets/{setId}/puzzles',
            uriVariables: ['setId' => new Link(fromClass: Set::class, identifiers: ['id'])],
            requirements: ['setId' => Set::UUID_PATTERN],
            paginationEnabled: false,
            provider: SetPuzzleProvider::class,
        ),
        new Post(
            uriTemplate: '/woodpecker/sets/{setId}/puzzles/{puzzleId}/replace',
            uriVariables: [
                'setId' => new Link(fromClass: Set::class, identifiers: ['id']),
                'puzzleId' => new Link(fromClass: self::class, identifiers: ['puzzleId']),
            ],
            requirements: ['setId' => Set::UUID_PATTERN, 'puzzleId' => '[A-Za-z0-9]{5}'],
            status: 200,
            openapi: new Operation(summary: 'Swaps the puzzle for another one of the same profile, at the same position.'),
            input: false,
            read: false,
            name: 'woodpecker_set_puzzle_replace',
            processor: ReplacePuzzleProcessor::class,
        ),
        // Item IRI only (answers 404), kept under the domain prefix.
        new NotExposed(uriTemplate: '/woodpecker/set-puzzles/{puzzleId}', requirements: ['puzzleId' => '[A-Za-z0-9]{5}']),
    ],
)]
final class SetPuzzle
{
    /** The Lichess id. */
    #[ApiProperty(identifier: true)]
    public string $puzzleId;
    /** 0-based position in the set's order. */
    public int $position;
    public int $rating;
    /** @var list<string> */
    public array $themes;
    /** Attempts resolved on it, every cycle run of the set included. */
    public int $played;
    public int $failed;

    public static function from(Puzzle $puzzle, int $position, int $played, int $failed): self
    {
        $view = new self();
        $view->puzzleId = $puzzle->getLichessId();
        $view->position = $position;
        $view->rating = $puzzle->getRating();
        $view->themes = $puzzle->getThemes();
        $view->played = $played;
        $view->failed = $failed;

        return $view;
    }
}
