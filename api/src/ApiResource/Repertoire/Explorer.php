<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Repertoire\Lichess\ExplorerClient;
use App\State\Repertoire\ExplorerProvider;

/**
 * Game statistics of a position from the Lichess opening explorer, through the server (the client
 * never calls Lichess, never sees a token): masters database, or Lichess games with speed and
 * rating filters. 422 invalid position or filter, 503 Lichess unavailable (Retry-After), 429.
 *
 * @phpstan-import-type ExplorerMove from ExplorerClient
 */
#[ApiResource(
    shortName: 'RepertoireExplorer',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/repertoires/explorer/{source}',
            requirements: ['source' => 'masters|lichess'],
            openapi: new Operation(parameters: [
                new Parameter('fen', 'query', 'Position (FEN)', required: true, schema: ['type' => 'string']),
                new Parameter('speeds', 'query', 'lichess: comma-separated speeds', schema: ['type' => 'string']),
                new Parameter('ratings', 'query', 'lichess: comma-separated rating groups', schema: ['type' => 'string']),
            ]),
            provider: ExplorerProvider::class,
        ),
    ],
)]
final class Explorer
{
    /** masters or lichess */
    #[ApiProperty(identifier: true)]
    public string $source;
    /** Normalized FEN of the position. */
    public string $fen;
    public int $white;
    public int $draws;
    public int $black;
    public int $total;
    /** @var list<ExplorerMove> most played first */
    public array $moves = [];
    /** @var array{eco: string, name: string}|null */
    public ?array $opening = null;
}
