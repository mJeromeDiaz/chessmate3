<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\Repertoire\Lichess\CloudEvalClient;
use App\State\Repertoire\CloudEvalProvider;

/**
 * The Lichess cloud evaluation of a position, through the server: found false when Lichess has
 * none (a clear message, not an error). 422 invalid position, 503 Lichess unavailable, 429.
 *
 * @phpstan-import-type CloudLine from CloudEvalClient
 */
#[ApiResource(
    shortName: 'RepertoireCloudEval',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/repertoires/cloud-eval',
            openapi: new Operation(parameters: [
                new Parameter('fen', 'query', 'Position (FEN)', required: true, schema: ['type' => 'string']),
                new Parameter('lines', 'query', '1 to 5 lines (default 3)', schema: ['type' => 'integer']),
            ]),
            provider: CloudEvalProvider::class,
        ),
    ],
)]
final class CloudEval
{
    /** Normalized FEN of the position. */
    #[ApiProperty(identifier: true)]
    public string $fen;
    public bool $found;
    public ?int $depth = null;
    public ?int $knodes = null;
    /** @var list<CloudLine> centipawns (or mate) from White's point of view, best first */
    public array $lines = [];
}
