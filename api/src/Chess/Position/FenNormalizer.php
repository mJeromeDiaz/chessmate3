<?php

declare(strict_types=1);

namespace App\Chess\Position;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;

/**
 * The normalized FEN of a position (docs/REPERTOIRE.md): piece placement, side to move, castling
 * rights (only those still possible), en passant square only if an en passant capture is legal,
 * without the move counters. Same rule as the "epd" column of lichess-org/chess-openings, and as
 * front/src/utils/chess/normalizeFen.js (both tested on api/tests/Fixtures/Chess/normalization.json).
 */
final class FenNormalizer
{
    /**
     * @throws InvalidPositionException
     */
    public function normalize(string $fen): string
    {
        return Rules::fromFen($fen)->normalizedFen();
    }

    /**
     * @throws InvalidPositionException
     */
    public function key(string $fen): PositionKey
    {
        return PositionKey::of($this->normalize($fen));
    }
}
