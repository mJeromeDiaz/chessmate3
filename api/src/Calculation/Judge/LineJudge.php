<?php

declare(strict_types=1);

namespace App\Calculation\Judge;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;

/**
 * Judges a line played at once (docs/CALCULATION.md, § 3): every half-move legal and the
 * solution's, defence included (played by itself since 2026-10-28: the player plays their own
 * moves only); a mate by the player ends the line right, even one sooner or by another move than
 * the solution's (as Lichess does). Pure.
 */
final class LineJudge
{
    /**
     * @param list<string> $expected the solution, UCI, both sides, the player's move first
     * @param list<string> $played   the line played, UCI
     *
     * @return array{right: bool, mistakeAt: int|null} the first half-move off the solution (or
     *                                                 where the line stops short, or its last
     *                                                 move when it lets the mate go), null when
     *                                                 right
     *
     * @throws InvalidPositionException
     * @throws IllegalLineException a half-move that is not legal, or one after the mate
     */
    public static function judge(string $fen, array $expected, array $played): array
    {
        $rules = Rules::fromFen($fen);
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
            if ($uci !== ($expected[$i] ?? null)) {
                return ['right' => false, 'mistakeAt' => $i];
            }
        }

        if (\count($played) === \count($expected)) {
            // The whole solution (a line that wins material).
            return ['right' => true, 'mistakeAt' => null];
        }

        // Stopped short.
        return ['right' => false, 'mistakeAt' => \count($played)];
    }
}
