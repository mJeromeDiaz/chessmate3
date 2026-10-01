<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Repertoire\StatsProvider;

/**
 * The user's repertoires at a glance (docs/REPERTOIRE.md § 15): cards to review, tests, and the
 * cards falling due over the next 7 local days.
 */
#[ApiResource(
    shortName: 'RepertoireOverview',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/repertoires/stats', provider: StatsProvider::class, name: 'repertoire_overview'),
    ],
)]
final class Overview
{
    /** @var list<array{id: string, name: string, color: string, cards: array{total: int, new: int, learning: int, review: int, due: int}, tests: int, successRate30: float|null, lastTestedAt: string|null}> */
    public array $repertoires = [];
    /** @var array{total: int, new: int, learning: int, review: int, due: int} */
    public array $cards;
    /** @var list<array{date: string, due: int}> */
    public array $forecast = [];
}
