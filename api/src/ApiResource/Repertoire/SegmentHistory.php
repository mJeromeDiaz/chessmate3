<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\State\Repertoire\StatsProvider;

/**
 * The history of a segment (docs/REPERTOIRE.md § 15): its last 50 presentations, retries included,
 * and those of the segments merged into it (beforeMerge).
 *
 * @phpstan-import-type Label from Stats
 * @phpstan-type PresentationRow array{id: string, segmentId: string, beforeMerge: bool, runId: string|null, unit: string, rank: int, round: int, status: string, firstErrorPly: int|null, positionsGraded: int, durationMs: int|null, moves: list<string>, label: Label, startedAt: string, finishedAt: string|null}
 */
#[ApiResource(
    shortName: 'RepertoireSegmentHistory',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/repertoires/{repertoireId}/segments/{id}',
            requirements: ['repertoireId' => Repertoire::UUID_PATTERN, 'id' => Repertoire::UUID_PATTERN],
            provider: StatsProvider::class,
        ),
    ],
)]
final class SegmentHistory
{
    /** The segment id. */
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $repertoireId;
    /** @var Label|null null when the segment is no longer presented (archived, or no user move) */
    public ?array $label;
    /** @var list<string> */
    public array $path = [];
    public bool $archived;
    public ?string $derivedFromSegmentId;
    public ?string $mergedIntoSegmentId;
    /** @var list<PresentationRow> newest first */
    public array $presentations = [];
}
