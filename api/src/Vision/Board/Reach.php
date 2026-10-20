<?php

declare(strict_types=1);

namespace App\Vision\Board;

use App\Chess\Attacks;

/**
 * Whether a piece can go to a square in one move (docs/VISION.md, § 3), on the board alone: a king,
 * queen, rook, bishop or knight reaches an empty square or an opponent's piece (never its king) it
 * attacks; pins ignored, as for the attacks. Pawns are never asked about (they move as they do not
 * take).
 */
final class Reach
{
    public static function canReach(string $fen, string $from, string $to): bool
    {
        $board = Attacks::board($fen);
        $piece = $board[$from] ?? null;
        if (null === $piece || 'p' === strtolower($piece) || $from === $to) {
            return false;
        }
        $white = ctype_upper($piece);
        $there = $board[$to] ?? null;
        if (null !== $there && (ctype_upper($there) === $white || 'k' === strtolower($there))) {
            return false;
        }

        return \in_array($from, Attacks::attackers($fen, $to, $white ? 'w' : 'b'), true);
    }
}
