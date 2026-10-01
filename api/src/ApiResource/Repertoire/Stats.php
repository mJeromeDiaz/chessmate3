<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Repertoire\StatsProvider;

/**
 * Statistics of one repertoire (docs/REPERTOIRE.md § 15). A test is the first presentation of a
 * segment in a run; fragile segments: at least 3 tests, by failure rate over their last 10.
 *
 * @phpstan-type Label array{opening: array{eco: string, name: string}|null, move: string|null}
 * @phpstan-type Tests array{total: int, succeeded: int, successRate: float|null, last7: int, last30: int, lastAt: string|null, lastStatus: string|null, recent: int, recentFailureRate: float|null}
 * @phpstan-type SegmentStats array{id: string, label: Label, path: list<string>, userMoveCount: int, derivedFromSegmentId: string|null, cards: array{new: int, due: int}, tests: Tests}
 */
#[ApiResource(
    shortName: 'RepertoireStats',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/repertoires/{id}/stats', requirements: ['id' => Repertoire::UUID_PATTERN], provider: StatsProvider::class),
    ],
)]
final class Stats
{
    /** The repertoire id. */
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $name;
    public string $color;
    /** @var array{total: int, new: int, learning: int, review: int, due: int} */
    public array $cards;
    /** @var list<array{date: string, due: int}> */
    public array $forecast = [];
    /** @var array{total: int, succeeded: int, failed: int, successRate: float|null, last7: int, last30: int, lastAt: string|null} */
    public array $tests;
    /** @var list<SegmentStats> active segments presented in tests, in display order */
    public array $segments = [];
    /** @var list<SegmentStats> at most 10, the most failed first */
    public array $fragile = [];
}
