<?php

declare(strict_types=1);

namespace App\ApiResource\PuzzleStreak;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\PuzzleStreak\OpeningsProvider;

/**
 * The openings a puzzle streak can draw from (docs/PUZZLE_STREAK.md): the user's own (repertoires,
 * Lichess games already read) with their colour, then every family of the catalogue with enough
 * puzzles for a side.
 *
 * @phpstan-import-type Family from \App\PuzzleStreak\Opening\OpeningChoices
 * @phpstan-import-type Mine from \App\PuzzleStreak\Opening\OpeningChoices
 */
#[ApiResource(
    shortName: 'PuzzleStreakOpenings',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/puzzle-streak/openings', provider: OpeningsProvider::class),
    ],
)]
final class Openings
{
    /** @var list<Mine> the most present first */
    public array $mine = [];

    /** @var list<Family> by name */
    public array $all = [];
}
