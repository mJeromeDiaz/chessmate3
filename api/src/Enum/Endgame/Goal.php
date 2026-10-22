<?php

declare(strict_types=1);

namespace App\Enum\Endgame;

/**
 * What the player must do in a lesson (docs/ENDGAMES.md, § 3), the result of the position always
 * kept on the way.
 */
enum Goal: string
{
    /** Mate the bare king. */
    case Mate = 'mate';
    /** Promote a pawn, the win kept. */
    case Promote = 'promote';
    /** Leave the opponent a bare king (win the pawn, the rook...), the win kept. */
    case Convert = 'convert';
    /** Keep the draw for a number of moves, or reach a dead draw (bare kings, stalemate...). */
    case Hold = 'hold';
}
