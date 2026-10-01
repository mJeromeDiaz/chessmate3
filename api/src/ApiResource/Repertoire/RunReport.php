<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Repertoire\StatsProvider;

/**
 * What a timed repertoire test presented (docs/REPERTOIRE.md § 15): its units in order, each with
 * its segments' presentations. The run's normalized recap stays on the run itself.
 *
 * @phpstan-import-type Label from Stats
 */
#[ApiResource(
    shortName: 'RepertoireRunReport',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/repertoires/runs/{id}', requirements: ['id' => Repertoire::UUID_PATTERN], provider: StatsProvider::class),
    ],
)]
final class RunReport
{
    /** The run id. */
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $status;
    /** @var list<array{unitId: string, unit: string, rank: int, round: int, status: string, label: Label, repertoireId: string, segments: list<array{segmentId: string, label: Label, status: string, firstErrorPly: int|null, positionsGraded: int, durationMs: int|null, moves: list<string>}>}> */
    public array $units = [];
}
