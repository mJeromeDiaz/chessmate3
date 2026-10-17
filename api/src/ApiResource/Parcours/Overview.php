<?php

declare(strict_types=1);

namespace App\ApiResource\Parcours;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Parcours\OverviewProvider;

/**
 * What a parcours can be (docs/PARCOURS.md): its themes and their tiers, its efforts, the user's
 * repertoires for the opening (with the family their puzzles come from), the puzzle rating the
 * tiers start from.
 */
#[ApiResource(
    shortName: 'ParcoursOverview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/parcours', provider: OverviewProvider::class),
    ],
)]
final class Overview
{
    /** @var list<array{theme: string, tiers: list<array{kind: string, themes: list<string>}>}> */
    public array $themes = [];

    /** @var list<array{effort: string, perTier: int, total: int, bonusXp: int}> */
    public array $efforts = [];

    /** @var list<array{id: string, name: string, color: string, opening: array{family: string, name: string}|null}> null: no opening with enough puzzles */
    public array $repertoires = [];

    /** The player's puzzle rating, the middle of each tier. */
    public int $rating;

    public int $ratingGap;
}
