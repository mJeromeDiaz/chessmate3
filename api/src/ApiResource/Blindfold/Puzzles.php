<?php

declare(strict_types=1);

namespace App\ApiResource\Blindfold;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Blindfold\PuzzlesProvider;

/**
 * The signed-in user's blindfold puzzles (docs/BLINDFOLD.md): the rules (levels, lengths, times)
 * and the results so far, by level and by length.
 *
 * @phpstan-type Counts array{played: int, solved: int, helped: int, failed: int}
 */
#[ApiResource(
    shortName: 'BlindfoldPuzzles',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/blindfold/puzzles', provider: PuzzlesProvider::class),
    ],
)]
final class Puzzles
{
    /** @var array{levels: list<array{key: string, min: int, max: int}>, lengths: list<int>, visibleSeconds: list<int>, hiddenSeconds: int, peeks: int} */
    public array $rules;

    /** @var Counts every puzzle played */
    public array $total;

    /** @var array<string, Counts> by level (easy, medium, hard), every level listed */
    public array $byLevel = [];

    /** @var array<int, Counts> by length (2, 3, 4: a JSON object), every length listed */
    public array $byLength = [];
}
