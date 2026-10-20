<?php

declare(strict_types=1);

namespace App\Vision\Board;

use App\Chess\Attacks;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use PChess\Chess\Move;

/**
 * The pieces en prise in a position (docs/VISION.md, § 3): those the side to move can take with a
 * legal move, when they are not defended or when their taker is worth less than they are. Kings are
 * never en prise; an en passant capture does not count.
 */
final class EnPrise
{
    /** The pieces' values (a king takes only what is not defended: its moves are legal). */
    public const VALUES = ['p' => 1, 'n' => 3, 'b' => 3, 'r' => 5, 'q' => 9, 'k' => 100];

    /**
     * @return list<string> the squares of the pieces en prise, sorted
     *
     * @throws InvalidPositionException
     */
    public static function squares(string $fen): array
    {
        $rules = Rules::fromFen($fen);
        $defender = 'w' === $rules->sideToMove() ? 'b' : 'w';
        $found = [];
        foreach ($rules->legalMoves() as $move) {
            $captured = $move->captured;
            if (null === $captured || 'k' === $captured || ($move->flags & Move::BITS['EP_CAPTURE']) > 0) {
                continue;
            }
            $taker = self::VALUES[$move->piece->getType()] ?? 0;
            $victim = self::VALUES[$captured] ?? 0;
            if ($taker < $victim || [] === Attacks::attackers($fen, $move->to, $defender)) {
                $found[$move->to] = true;
            }
        }
        $squares = array_map('strval', array_keys($found));
        sort($squares);

        return $squares;
    }
}
