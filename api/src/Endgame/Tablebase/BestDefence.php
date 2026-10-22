<?php

declare(strict_types=1);

namespace App\Endgame\Tablebase;

use App\Enum\Endgame\Result;

/**
 * The opponent's moves at the top of the slider (« Parfait », docs/ENDGAMES.md, § 5): the moves that
 * keep its result, the longest resistance when it is lost, the fastest win when it wins. Several
 * moves can tie: the browser picks among them with Maia's preferred one, the server accepts any.
 */
final class BestDefence
{
    /**
     * @return list<string> UCI, empty when the result is unknown
     */
    public static function moves(Answer $answer): array
    {
        $result = $answer->result();
        $keeping = $answer->keepingMoves();
        if (null === $result || [] === $keeping) {
            return [];
        }
        if (Result::Draw === $result) {
            return array_map(static fn (MoveAnswer $move): string => $move->uci, $keeping);
        }

        // Plies to the end: to mate when known, else to the next capture or pawn move.
        $distance = static fn (MoveAnswer $move): int => $move->pliesToMate() ?? abs($move->dtz ?? 0);
        $distances = array_map($distance, $keeping);
        $target = Result::Loss === $result ? max($distances) : min($distances);

        return array_values(array_map(
            static fn (MoveAnswer $move): string => $move->uci,
            array_filter($keeping, static fn (MoveAnswer $move): bool => $distance($move) === $target),
        ));
    }
}
