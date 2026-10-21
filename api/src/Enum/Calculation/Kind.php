<?php

declare(strict_types=1);

namespace App\Enum\Calculation;

/**
 * What a line of the calculation module asks (docs/CALCULATION.md). `line` is judged by the
 * server against the puzzle's solution; the others, chapter 3 of the calculation path (§ 2 quater),
 * by Stockfish in the player's browser (docs/ENGINE.md): the server checks only that the moves are
 * legal, the line's shape and its time. Stored as its value: add cases, never rename one.
 */
enum Kind: string
{
    case Line = 'line';
    /** 3.1: the player's first move shown played, the best of two replies of the opponent's to choose. */
    case Reply = 'reply';
    /** 3.2: the opponent has exactly two legal replies to the player's first move: a line against each. */
    case TwoReplies = 'two_replies';
    /** 3.3: the opponent threatens mate in one; the line that parries it. */
    case Defence = 'defence';
    /** 3.4: the opponent's reply is a check; the line that still wins. */
    case Intermezzo = 'intermezzo';
    /**
     * The mini-boss: the first move to choose among forcing moves (COMBOS, the others refuted),
     * then the rest of the line.
     */
    case Combo = 'combo';
    /**
     * The Avocat du diable (boss of chapter 3): the player's own move, then the opponent's best
     * reply to it, then the player's line on; a move that does not hold is a failure, its
     * refutation still to be found.
     */
    case Advocate = 'advocate';

    /** Judged by the engine in the browser, not against the solution. */
    public function isEngine(): bool
    {
        return self::Line !== $this;
    }

    /**
     * The Lichess themes drawn from when the lesson names none (else by the line's length).
     *
     * @return list<string>
     */
    public function themes(): array
    {
        return self::Defence === $this ? ['defensiveMove'] : [];
    }
}
