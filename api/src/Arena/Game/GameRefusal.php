<?php

declare(strict_types=1);

namespace App\Arena\Game;

/**
 * Why the arena refused a request (docs/ARENA.md, § 4); the value travels to the client.
 */
enum GameRefusal: string
{
    /** Not a boss of the roster (404). */
    case UnknownBoss = 'arena_unknown_boss';
    /** Not met on its path yet, or not built (409). */
    case BossLocked = 'arena_boss_locked';
    /** Another game is in progress (409): resume or cancel it first. */
    case GameInProgress = 'arena_game_in_progress';
    /** Not the player's game (404). */
    case NotFound = 'arena_game_not_found';
    /** The game is over (409). */
    case Over = 'arena_game_over';
    /** The move does not follow the moves played (409): the client reloads the game. */
    case OutOfSync = 'arena_out_of_sync';
    /** An illegal move (422). */
    case Illegal = 'arena_illegal_move';
    /** A path's boss, or a free one: never sold (409). */
    case NotForSale = 'arena_boss_not_for_sale';
    /** Already bought (409). */
    case Owned = 'arena_boss_owned';
    /** Not enough crystals (409). */
    case Insufficient = 'arena_insufficient_crystals';
}
