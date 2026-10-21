<?php

declare(strict_types=1);

namespace App\Calculation\Judge;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;

/**
 * The shape of a line the engine judged in the browser (docs/CALCULATION.md, § 2 quater): what the
 * server can check without an engine. Pure.
 */
final class LineShape
{
    /**
     * Whether a line is played to its end: as many half-moves as the solution has, or fewer ending
     * on the player's mate. Every half-move must be legal, none after a mate, never more than the
     * solution's.
     *
     * @param list<string> $played UCI, both sides, the player's move first
     *
     * @throws InvalidPositionException
     * @throws IllegalLineException
     */
    public static function complete(string $fen, array $played, int $plies): bool
    {
        if (\count($played) > $plies) {
            throw new IllegalLineException($plies);
        }
        $rules = Rules::fromFen($fen);
        foreach ($played as $i => $uci) {
            if (null === $rules->playUci($uci)) {
                throw new IllegalLineException($i);
            }
            if ($rules->inCheckmate()) {
                if ($i !== \count($played) - 1) {
                    throw new IllegalLineException($i + 1);
                }

                // The player's half-moves are the even ones: a line the opponent mates is no win.
                return 0 === $i % 2;
            }
        }

        return \count($played) === $plies;
    }

    /**
     * The opponent's legal replies to the player's first move (UCI).
     *
     * @return list<string>
     *
     * @throws InvalidPositionException
     * @throws IllegalLineException the first move is not legal
     */
    public static function replies(string $fen, string $first): array
    {
        $rules = Rules::fromFen($fen);
        if (null === $rules->playUci($first)) {
            throw new IllegalLineException(0);
        }

        return array_map(Rules::uci(...), $rules->legalMoves());
    }
}
