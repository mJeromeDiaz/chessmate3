<?php

declare(strict_types=1);

namespace App\Puzzle\Opening;

/**
 * Lichess opening families and the catalogue's opening tags (docs/PUZZLE_IMPORT.md): a game's
 * opening "Queen's Pawn Game: London System" belongs to the family "Queen's Pawn Game", whose
 * puzzles carry the first tag "Queens_Pawn_Game" (`puzzle.opening_family`). Pure.
 */
final class OpeningFamily
{
    /** The tag of a family, or of a full opening name (its part before ":"). */
    public static function tag(string $family): string
    {
        $family = trim(explode(':', $family, 2)[0]);
        $kept = preg_replace('/[^A-Za-z0-9 \-]/', '', $family) ?? '';

        return str_replace(' ', '_', trim(preg_replace('/\s+/', ' ', $kept) ?? ''));
    }
}
