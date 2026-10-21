<?php

declare(strict_types=1);

namespace App\Calculation\Drawing;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;

/**
 * What the side not to move threatens (docs/CALCULATION.md, § 2 quater): the position seen as if
 * it had the move. Pure.
 */
final class Threat
{
    /**
     * Whether the side not to move would mate in one if it had the move; never when the side to
     * move is in check (a check is no threat, and the position turned around would not be legal).
     *
     * @throws InvalidPositionException
     */
    public static function mateInOne(string $fen): bool
    {
        if (Rules::fromFen($fen)->inCheck()) {
            return false;
        }
        $fields = explode(' ', trim($fen));
        $fields[1] = 'w' === ($fields[1] ?? 'w') ? 'b' : 'w';
        // No en passant for the side given the move.
        $fields[3] = '-';
        $turned = Rules::fromFen(implode(' ', $fields));
        foreach ($turned->legalMoves() as $move) {
            $turned->playUci(Rules::uci($move));
            $mates = $turned->inCheckmate();
            $turned->undo();
            if ($mates) {
                return true;
            }
        }

        return false;
    }
}
