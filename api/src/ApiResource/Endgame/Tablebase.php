<?php

declare(strict_types=1);

namespace App\ApiResource\Endgame;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation;
use ApiPlatform\OpenApi\Model\Parameter;
use App\State\Endgame\TablebaseProvider;

/**
 * What the Lichess tablebase says of a position of the endgames module, through the server and
 * kept there (docs/ENDGAMES.md, § 2): asked after each move played, for the verdict at once.
 * `known` false: more than 7 pieces, castling rights, or unknown to Lichess. 422 invalid position,
 * 503 Lichess unavailable (reason in X-Lichess-Unavailable: the browser's engine takes over), 429.
 *
 * @phpstan-type TablebaseMove array{uci: string, result: 'win'|'draw'|'loss'|null, dtm: int|null, dtz: int|null, zeroing: bool}
 */
#[ApiResource(
    shortName: 'EndgameTablebase',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(
            uriTemplate: '/endgames/tablebase',
            openapi: new Operation(parameters: [
                new Parameter('fen', 'query', 'Position (FEN)', required: true, schema: ['type' => 'string']),
            ]),
            provider: TablebaseProvider::class,
        ),
    ],
)]
final class Tablebase
{
    /** Normalized FEN of the position. */
    #[ApiProperty(identifier: true)]
    public string $fen;
    public bool $known = false;
    /** For the side to move: win, draw, loss, or null when uncertain. */
    public ?string $result = null;
    public ?int $dtm = null;
    public ?int $dtz = null;
    public bool $checkmate = false;
    public bool $stalemate = false;
    public bool $insufficientMaterial = false;
    /** @var list<TablebaseMove> every legal move, best first; result for the side that plays it */
    public array $moves = [];
    /** @var list<string> the opponent's best defence here (UCI), for the top of the slider */
    public array $defence = [];
}
