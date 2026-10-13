<?php

declare(strict_types=1);

namespace App\Enum\Chess;

/**
 * The kind of an endgame, from the pieces on the board besides kings and pawns, both sides together
 * (docs/COACH.md). Stored as its value: add cases, never rename one.
 */
enum EndgameType: string
{
    /** Kings and pawns only. */
    case Pawn = 'pawn';
    /** Rooks only. */
    case Rook = 'rook';
    /** Bishops and knights only. */
    case Minor = 'minor';
    /** Queens only. */
    case Queen = 'queen';
    /** Rooks and minor pieces, no queen. */
    case RookMinor = 'rook_minor';
    /** A queen with other pieces. */
    case Mixed = 'mixed';

    public function label(): string
    {
        return match ($this) {
            self::Pawn => 'Pions',
            self::Rook => 'Tours',
            self::Minor => 'Pièces mineures',
            self::Queen => 'Dames',
            self::RookMinor => 'Tours et pièces mineures',
            self::Mixed => 'Mixtes',
        };
    }
}
