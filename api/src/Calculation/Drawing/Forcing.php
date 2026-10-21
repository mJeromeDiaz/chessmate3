<?php

declare(strict_types=1);

namespace App\Calculation\Drawing;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;

/**
 * The forcing moves of a position (docs/CALCULATION.md, § 2 quater): its checks and captures, the
 * combinations the mini-boss of chapter 3 offers. Pure.
 */
final class Forcing
{
    /**
     * The side to move's checks and captures (UCI).
     *
     * @return list<string>
     *
     * @throws InvalidPositionException
     */
    public static function moves(string $fen): array
    {
        $rules = Rules::fromFen($fen);
        $forcing = [];
        foreach ($rules->legalMoves() as $move) {
            $uci = Rules::uci($move);
            $rules->playUci($uci);
            $check = $rules->inCheck();
            $rules->undo();
            if ($check || null !== $move->captured) {
                $forcing[] = $uci;
            }
        }

        return $forcing;
    }
}
