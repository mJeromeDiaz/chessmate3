<?php

declare(strict_types=1);

namespace App\Chess;

/**
 * Whether a move offers material (docs/BRILLIANCY.md, § 2): the piece moved can be taken at once
 * and the exchange loses at least {@see self::MIN_GIVEN} pawns, on the board alone, without an
 * engine. The opponent takes with their cheapest legal capturer (pins and checks respected) or
 * leaves it; when the square stays defended, the capturer is taken back. A one-step exchange
 * balance: deeper exchanges, x-rays and threats elsewhere are ignored.
 *
 * Board: square => FEN letter, as {@see Attacks::board()} gives it.
 */
final class Sacrifice
{
    /** Pawns per piece; the king is never offered. */
    public const VALUES = ['p' => 1, 'n' => 3, 'b' => 3, 'r' => 5, 'q' => 9, 'k' => 0];

    /** A minor piece for a pawn, the exchange (rook for a minor piece) or more. */
    public const MIN_GIVEN = 2;

    /**
     * True when the move offers at least {@see self::MIN_GIVEN} pawns.
     *
     * @param array<string, string> $board
     */
    public static function offers(array $board, string $uci): bool
    {
        return (self::given($board, $uci) ?? 0) >= self::MIN_GIVEN;
    }

    /**
     * The material the move gives at once, in pawns (0: nothing), or null when it cannot be a
     * sacrifice: no piece on its square, a king move, a promotion.
     *
     * @param array<string, string> $board
     */
    public static function given(array $board, string $uci): ?int
    {
        if (1 !== preg_match('/^([a-h][1-8])([a-h][1-8])([qrbn]?)$/', $uci, $m) || '' !== $m[3]) {
            return null;
        }
        [, $from, $to] = $m;
        $piece = $board[$from] ?? null;
        if (null === $piece || 'k' === strtolower($piece)) {
            return null;
        }
        $mover = ctype_upper($piece) ? 'w' : 'b';
        $other = 'w' === $mover ? 'b' : 'w';
        $taken = isset($board[$to]) ? self::value($board[$to]) : (self::isEnPassant($board, $from, $to) ? 1 : 0);
        $after = self::play($board, $uci);

        $cheapest = null;
        foreach (Attacks::attackersOn($after, $to, $other) as $square) {
            $value = self::value($after[$square] ?? '');
            if ((null === $cheapest || $value < $cheapest[1]) && self::isLegalCapture($after, $square, $to, $other)) {
                $cheapest = [$square, $value];
            }
        }
        if (null === $cheapest) {
            return 0;
        }
        // Taken, then taken back while the square stays defended.
        $captured = self::capture($after, $cheapest[0], $to);
        $back = [] !== Attacks::attackersOn($captured, $to, $mover) ? $cheapest[1] : 0;
        $balance = min($taken, $taken - self::value($piece) + $back);

        return max(0, -$balance);
    }

    /**
     * The board after a move (a legal one: nothing is checked): castling moves its rook, en
     * passant removes the pawn taken, a promotion puts the new piece.
     *
     * @param array<string, string> $board
     *
     * @return array<string, string>
     */
    public static function play(array $board, string $uci): array
    {
        if (1 !== preg_match('/^([a-h][1-8])([a-h][1-8])([qrbn]?)$/', $uci, $m) || !isset($board[$m[1]])) {
            throw new \InvalidArgumentException(\sprintf('Not a move of this board: %s.', $uci));
        }
        [, $from, $to, $promotion] = $m;
        $piece = $board[$from];
        $white = ctype_upper($piece);
        if (self::isEnPassant($board, $from, $to)) {
            unset($board[$to[0].$from[1]]);
        }
        if ('k' === strtolower($piece) && 2 === abs(\ord($to[0]) - \ord($from[0]))) {
            [$rookFrom, $rookTo] = 'g' === $to[0] ? ['h', 'f'] : ['a', 'd'];
            if (isset($board[$rookFrom.$from[1]])) {
                $board[$rookTo.$from[1]] = $board[$rookFrom.$from[1]];
                unset($board[$rookFrom.$from[1]]);
            }
        }
        unset($board[$from]);
        $board[$to] = '' === $promotion ? $piece : ($white ? strtoupper($promotion) : $promotion);

        return $board;
    }

    private static function value(string $letter): int
    {
        return self::VALUES[strtolower($letter)] ?? 0;
    }

    /**
     * @param array<string, string> $board
     */
    private static function isEnPassant(array $board, string $from, string $to): bool
    {
        return 'p' === strtolower($board[$from] ?? '') && $from[0] !== $to[0] && !isset($board[$to]);
    }

    /**
     * Whether $color may take on $to from $from: its king is not left attacked.
     *
     * @param array<string, string> $board
     * @param 'w'|'b'               $color
     */
    private static function isLegalCapture(array $board, string $from, string $to, string $color): bool
    {
        $captured = self::capture($board, $from, $to);
        $king = array_search('w' === $color ? 'K' : 'k', $captured, true);

        return false === $king || [] === Attacks::attackersOn($captured, (string) $king, 'w' === $color ? 'b' : 'w');
    }

    /**
     * @param array<string, string> $board
     *
     * @return array<string, string>
     */
    private static function capture(array $board, string $from, string $to): array
    {
        $board[$to] = $board[$from];
        unset($board[$from]);

        return $board;
    }
}
