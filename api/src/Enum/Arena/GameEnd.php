<?php

declare(strict_types=1);

namespace App\Enum\Arena;

/**
 * How a game of the arena ended (docs/ARENA.md). Stored as its value: add cases, never rename one.
 */
enum GameEnd: string
{
    case Checkmate = 'checkmate';
    case Resignation = 'resignation';
    /** The player's clock ran out (the boss never loses on time). */
    case Timeout = 'timeout';
    /** The boss had the move and nobody came back to play it. */
    case Abandonment = 'abandonment';
    case Stalemate = 'stalemate';
    case Repetition = 'repetition';
    case FiftyMoves = 'fifty_moves';
    case InsufficientMaterial = 'insufficient_material';
    case Aborted = 'aborted';
}
