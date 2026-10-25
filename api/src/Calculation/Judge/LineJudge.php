<?php

declare(strict_types=1);

namespace App\Calculation\Judge;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;

/**
 * Judges a line played at once (docs/CALCULATION.md, § 3): every half-move legal; a mate by the
 * player ends the line right, even one sooner or by another move than the solution's (as Lichess
 * does). A line that wins material is the solution's, defence included, to its end. A mate's line
 * (chosen 2026-10-25) takes any defence, and a move of the player off the solution when it still
 * mates by force within the moves left ({@see MateSearch}; a search out of budget proves nothing).
 * Pure.
 */
final class LineJudge
{
    /**
     * @param list<string> $expected the solution, UCI, both sides, the player's move first
     * @param list<string> $played   the line played, UCI
     * @param bool         $mate     the solution mates: other defences and forced mates accepted
     *
     * @return array{right: bool, mistakeAt: int|null} the first half-move off the solution, or that
     *                                                 lets the mate go (or where the line stops
     *                                                 short), null when right
     *
     * @throws InvalidPositionException
     * @throws IllegalLineException a half-move that is not legal, or one after the mate
     */
    public static function judge(string $fen, array $expected, array $played, bool $mate = false): array
    {
        $rules = Rules::fromFen($fen);
        // The player's moves of the solution, the mating one included.
        $moves = intdiv(\count($expected) + 1, 2);
        $onSolution = true;
        foreach ($played as $i => $uci) {
            if (null === $rules->playUci($uci)) {
                throw new IllegalLineException($i);
            }
            if ($rules->inCheckmate()) {
                if ($i !== \count($played) - 1) {
                    throw new IllegalLineException($i + 1);
                }

                // The player's half-moves are the even ones: a defence that mates is no solution.
                return 0 === $i % 2 ? ['right' => true, 'mistakeAt' => null] : ['right' => false, 'mistakeAt' => $i];
            }
            $onSolution = $onSolution && $uci === ($expected[$i] ?? null);
            if ($onSolution) {
                continue;
            }
            if (!$mate) {
                return ['right' => false, 'mistakeAt' => $i];
            }
            // Off the solution: any defence; a move of the player that still mates by force.
            if (0 === $i % 2 && !self::stillMates($rules, $moves - intdiv($i, 2) - 1)) {
                return ['right' => false, 'mistakeAt' => $i];
            }
        }

        if ($onSolution && \count($played) === \count($expected)) {
            // The whole solution (a line that wins material).
            return ['right' => true, 'mistakeAt' => null];
        }

        // Stopped short, or a mate's whole length without the mate: its last move let it go.
        return ['right' => false, 'mistakeAt' => \count($played) >= \count($expected) ? \count($played) - 1 : \count($played)];
    }

    /** The defence to move: mated within $moves more moves of the player whatever it plays. */
    private static function stillMates(Rules $rules, int $moves): bool
    {
        if ($moves < 1) {
            return false;
        }
        try {
            return MateSearch::forced($rules, $moves);
        } catch (SearchBudgetExceededException) {
            return false;
        }
    }
}
