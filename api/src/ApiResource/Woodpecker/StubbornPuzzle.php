<?php

declare(strict_types=1);

namespace App\ApiResource\Woodpecker;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\NotExposed;
use App\State\Woodpecker\StubbornProvider;

/**
 * A puzzle of a set failed in at least two cycles. Replayable freely (unrated) through
 * POST /puzzles/attempts {replayOf}.
 */
#[ApiResource(
    shortName: 'WoodpeckerStubbornPuzzle',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new GetCollection(
            uriTemplate: '/woodpecker/sets/{setId}/stubborn',
            uriVariables: ['setId' => new Link(fromClass: Set::class, identifiers: ['id'])],
            requirements: ['setId' => Set::UUID_PATTERN],
            paginationEnabled: false,
            provider: StubbornProvider::class,
        ),
        // Item IRI only (answers 404), kept under the domain prefix.
        new NotExposed(uriTemplate: '/woodpecker/stubborn/{puzzleId}', requirements: ['puzzleId' => '[A-Za-z0-9]{5}']),
    ],
)]
final class StubbornPuzzle
{
    #[ApiProperty(identifier: true)]
    public string $puzzleId;
    public int $rating;
    /** @var list<string> */
    public array $themes;
    public int $failedCycles;
}
