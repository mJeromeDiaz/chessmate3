<?php

declare(strict_types=1);

namespace App\Enum\Arena;

/**
 * Where a game of the arena stands (docs/ARENA.md), for the player. Stored as its value: add cases,
 * never rename one.
 */
enum GameStatus: string
{
    case Active = 'active';
    case Won = 'won';
    case Lost = 'lost';
    case Drawn = 'drawn';
    /** Cancelled by the player (« Annuler », « Recommencer »): counted nowhere, no XP. */
    case Aborted = 'aborted';
}
