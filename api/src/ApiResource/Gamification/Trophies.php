<?php

declare(strict_types=1);

namespace App\ApiResource\Gamification;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Gamification\Trophy\TrophyEvaluator;
use App\State\Gamification\SummaryProvider;

/**
 * The signed-in user's trophies (docs/GAMIFICATION.md): every trophy of the catalogue, won (with
 * the date of the feat) or not (with the progress towards its goal). Reading them stores the ones
 * just reached.
 *
 * @phpstan-import-type TrophyView from TrophyEvaluator
 */
#[ApiResource(
    shortName: 'GamificationTrophies',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/gamification/trophies', provider: SummaryProvider::class),
    ],
)]
final class Trophies
{
    /** @var list<TrophyView> in the catalogue's order */
    public array $trophies = [];
}
