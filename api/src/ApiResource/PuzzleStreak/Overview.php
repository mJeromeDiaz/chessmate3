<?php

declare(strict_types=1);

namespace App\ApiResource\PuzzleStreak;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\PuzzleStreak\OverviewProvider;

/**
 * The signed-in user's puzzle streaks (docs/PUZZLE_STREAK.md): the rules (ramps, topics), the
 * records by ramp and topic, and the puzzles solved and missed so far.
 */
#[ApiResource(
    shortName: 'PuzzleStreakOverview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/puzzle-streak', provider: OverviewProvider::class),
    ],
)]
final class Overview
{
    /** @var array{ladder: array{start: int, step: int, max: int}, tiers: array{start: int, width: int, size: int, last: int}, topics: list<array{key: string, category: string}>, minOpeningPuzzles: int, singleBudgetSeconds: int, recordMin: int} */
    public array $rules;

    /** @var list<array{ramp: string, topic: string, best: int, streaks: int, at: string|null}> the longest first */
    public array $records = [];

    /** @var array{solved: int, failed: int} */
    public array $total;
}
