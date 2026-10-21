<?php

declare(strict_types=1);

namespace App\Calculation\Drawing;

use App\Chess\Attacks;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use Random\Randomizer;

/**
 * Draws the question asked once a line is played (docs/CALCULATION.md, § 2 bis), on the position
 * its solution reaches: where a piece the line moved now stands, or what stands on a square the line
 * touched (left empty, or taken). Half the time each, `where` when the line moved no piece still on
 * the board.
 */
final class QuestionDrawer
{
    /**
     * @param list<string> $solution UCI
     *
     * @throws InvalidPositionException
     */
    public static function draw(string $fen, array $solution, Randomizer $random = new Randomizer()): Question
    {
        $rules = Rules::fromFen($fen);
        $touched = [];
        $arrivals = [];
        foreach ($solution as $uci) {
            if (null === $rules->playUci($uci)) {
                throw new InvalidPositionException(\sprintf('The solution move %s does not replay.', $uci));
            }
            $touched[substr($uci, 0, 2)] = true;
            $touched[substr($uci, 2, 2)] = true;
            $arrivals[substr($uci, 2, 2)] = true;
        }
        $final = $rules->fen();
        $board = Attacks::board($final);
        // The pieces the line moved, where they stand at its end.
        $moved = array_values(array_unique(array_intersect_key($board, $arrivals)));
        if ([] !== $moved && 0 === $random->getInt(0, 1)) {
            $piece = $moved[$random->getInt(0, \count($moved) - 1)];

            return new Question(Question::WHERE, $piece, $final, array_keys(array_filter($board, static fn (string $p): bool => $p === $piece)));
        }
        $squares = array_keys($touched);
        $square = $squares[$random->getInt(0, \count($squares) - 1)];

        return new Question(Question::WHAT, $square, $final, isset($board[$square]) ? [$board[$square]] : []);
    }
}
