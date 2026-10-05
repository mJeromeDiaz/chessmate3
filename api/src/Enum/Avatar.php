<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The chess piece the user picked as avatar (design "Profil"), each with its own colour in the
 * SPA. Stored as its value: add cases, never rename one.
 */
enum Avatar: string
{
    case Knight = 'knight';
    case Bishop = 'bishop';
    case Queen = 'queen';
    case Rook = 'rook';
    case King = 'king';
    case Pawn = 'pawn';
}
