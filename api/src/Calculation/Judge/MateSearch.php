<?php

declare(strict_types=1);

namespace App\Calculation\Judge;

use App\Chess\Rules;
use PChess\Chess\Move;

/**
 * Whether a move off a mate's solution still mates by force (docs/CALCULATION.md, § 3): after it,
 * whatever the defence, the player mates within the moves left. Checks are tried first; the search
 * stops at {@see self::BUDGET} positions (about 60 ms per 5 000 measured on 2026-10-25, without
 * Xdebug: a quiet first move of a mate in 4 could otherwise take seconds). It plays on the position
 * given and leaves it as it found it, out of budget too.
 */
final class MateSearch
{
    /** Positions looked at, at most, for one move (about half a second). */
    public const BUDGET = 40_000;

    private int $left;

    private function __construct(private readonly Rules $rules, int $budget)
    {
        $this->left = $budget;
    }

    /**
     * @param Rules $rules the position after the player's move, the defence to move
     * @param int   $moves the player's moves left to mate (1: the next one mates)
     *
     * @throws SearchBudgetExceededException
     */
    public static function forced(Rules $rules, int $moves, int $budget = self::BUDGET): bool
    {
        return (new self($rules, $budget))->defended($moves);
    }

    /** The defence to move: mated already, or mated within $moves whatever it plays. */
    private function defended(int $moves): bool
    {
        $replies = $this->rules->legalMoves();
        if ([] === $replies) {
            // Mate, or stalemate (no mate).
            return $this->rules->inCheck();
        }
        if ($moves < 1) {
            return false;
        }
        foreach ($replies as $reply) {
            $this->push($reply);
            try {
                $mates = $this->mates($moves);
            } finally {
                $this->rules->pop();
            }
            if (!$mates) {
                return false;
            }
        }

        return true;
    }

    /** The player to move: some move mates within $moves. */
    private function mates(int $moves): bool
    {
        foreach ($this->ordered($this->rules->legalMoves()) as $move) {
            $this->push($move);
            try {
                $mates = 1 === $moves ? $this->rules->inCheckmate() : $this->defended($moves - 1);
            } finally {
                $this->rules->pop();
            }
            if ($mates) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks first: most mates of a puzzle are found there.
     *
     * @param list<Move> $moves
     *
     * @return list<Move>
     */
    private function ordered(array $moves): array
    {
        $checks = [];
        $others = [];
        foreach ($moves as $move) {
            $this->push($move);
            $check = $this->rules->inCheck();
            $this->rules->pop();
            if ($check) {
                $checks[] = $move;
            } else {
                $others[] = $move;
            }
        }

        return [...$checks, ...$others];
    }

    private function push(Move $move): void
    {
        if (--$this->left < 0) {
            throw new SearchBudgetExceededException('The mate search ran out of positions.');
        }
        $this->rules->push($move);
    }
}
