<?php

declare(strict_types=1);

namespace App\Repertoire\Lichess;

/**
 * Lichess writes castling as the king moving onto its own rook (`e1h1`, `e8a8`, the Chess960
 * form); everything else here (chess.js, {@see \App\Chess\Rules}) expects the king's destination
 * (`e1g1`, `e8c8`).
 */
final class Castling
{
    private const KING_TO_ROOK = [
        'e1h1' => 'e1g1',
        'e1a1' => 'e1c1',
        'e8h8' => 'e8g8',
        'e8a8' => 'e8c8',
    ];

    /**
     * The standard UCI of a castling move written king-to-rook; any other move unchanged. Only a
     * castling one: a rook on e1 may really go to h1, so the caller says whether it is one.
     */
    public static function standard(string $uci, bool $isCastling): string
    {
        return $isCastling ? (self::KING_TO_ROOK[$uci] ?? $uci) : $uci;
    }
}
