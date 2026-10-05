<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * The colours of the chessboard squares chosen in the SPA (design "Profil": Bois, Verre, Pastel,
 * Tournoi, Ardoise). Stored as its value: add cases, never rename one.
 */
enum BoardTheme: string
{
    case Wood = 'wood';
    case Glass = 'glass';
    case Pastel = 'pastel';
    case Tournament = 'tournament';
    case Slate = 'slate';
}
