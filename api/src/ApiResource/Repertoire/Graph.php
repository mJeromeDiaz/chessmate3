<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use App\Repertoire\Graph\GraphReader;
use App\State\Repertoire\GraphProvider;

/**
 * The whole graph of a repertoire, for the editor (docs/REPERTOIRE.md): positions (normalized FEN,
 * side to move, depth, opening name when the position is a named one), moves (role, order,
 * comment as plain text, NAGs, canonical flag, segment) and active segments. One request, plain
 * arrays: a large repertoire has thousands of positions.
 *
 * @phpstan-import-type PositionRow from GraphReader
 * @phpstan-import-type MoveRow from GraphReader
 * @phpstan-import-type SegmentRow from GraphReader
 */
#[ApiResource(
    shortName: 'RepertoireGraph',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/repertoires/{id}/graph', requirements: ['id' => Repertoire::UUID_PATTERN], provider: GraphProvider::class),
    ],
)]
final class Graph
{
    /** The repertoire id. */
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $name;
    public string $color;
    public int $version;
    public string $rootPositionId;
    /** @var list<PositionRow> */
    public array $positions = [];
    /** @var list<MoveRow> */
    public array $moves = [];
    /** @var list<SegmentRow> */
    public array $segments = [];
}
