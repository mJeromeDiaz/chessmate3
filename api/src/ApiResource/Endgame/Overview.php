<?php

declare(strict_types=1);

namespace App\ApiResource\Endgame;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Endgame\OverviewProvider;

/**
 * The signed-in user's endgames (docs/ENDGAMES.md, § 7): the settings' bounds, the lessons in the
 * catalogue's order with their variants and the user's results, the Elo proposed.
 *
 * @phpstan-type LessonView array{key: string, family: string, goal: string, result: string, holdMoves: int|null, variants: int, played: int, succeeded: int, lastPlayedAt: string|null}
 */
#[ApiResource(
    shortName: 'EndgameOverview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/endgames', provider: OverviewProvider::class),
    ],
)]
final class Overview
{
    /** @var array{runMinutes: list<int>, defaultMinutes: int, minElo: int, maxElo: int, eloStep: int, xp: array{weak: int, strong: int, perfect: int, failed: int, strongElo: int}} */
    public array $rules;

    /** Maia's Elo proposed: the user's puzzle rating, rounded to the slider's step. */
    public int $elo;

    /** @var list<LessonView> */
    public array $lessons = [];
}
