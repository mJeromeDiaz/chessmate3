<?php

declare(strict_types=1);

namespace App\ApiResource\Blindfold;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Blindfold\RecallProvider;

/**
 * The signed-in user's position recall (docs/BLINDFOLD_RECALL.md): the rules (the twenty levels,
 * times, unlock rule) and where the user stands on each level.
 *
 * @phpstan-import-type LevelProgress from \App\Blindfold\Recall\Progress
 */
#[ApiResource(
    shortName: 'BlindfoldRecall',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/blindfold/recall', provider: RecallProvider::class),
    ],
)]
final class Recall
{
    /** @var array{levels: list<array{level: int, stage: int, visibleSeconds: int, pieces: list<string>, puzzlePieces: array{int, int}|null, announced: int}>, hiddenSeconds: int, unlockExact: int, closeAccuracy: float, freeBudgetSeconds: int, maxLevel: int} */
    public array $rules;

    /** @var list<LevelProgress> every level, first to last */
    public array $levels = [];

    /** The highest level open to the user. */
    public int $highest = 1;
}
