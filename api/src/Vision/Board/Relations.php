<?php

declare(strict_types=1);

namespace App\Vision\Board;

use App\Chess\Attacks;

/**
 * The relations between the pieces of a position (docs/VISION.md, § 2 quater), on the board alone
 * as the attacks are (pins ignored); kings are never counted among the pieces attacked or defended.
 */
final class Relations
{
    private const DIRECTIONS = [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]];

    /**
     * The pieces of $color attacked at least once by the other side.
     *
     * @param 'w'|'b' $color
     *
     * @return list<string> sorted
     */
    public static function attacked(string $fen, string $color): array
    {
        $other = 'w' === $color ? 'b' : 'w';

        return self::of($fen, $color, static fn (string $square): bool => [] !== Attacks::attackers($fen, $square, $other));
    }

    /**
     * The pieces of $color defended at least once by their own side.
     *
     * @param 'w'|'b' $color
     *
     * @return list<string> sorted
     */
    public static function defended(string $fen, string $color): array
    {
        return self::of($fen, $color, static fn (string $square): bool => [] !== Attacks::attackers($fen, $square, $color));
    }

    /**
     * The pieces of $color no piece of their side defends.
     *
     * @param 'w'|'b' $color
     *
     * @return list<string> sorted
     */
    public static function undefended(string $fen, string $color): array
    {
        return self::of($fen, $color, static fn (string $square): bool => [] === Attacks::attackers($fen, $square, $color));
    }

    /**
     * The pieces aligned with the one on $square: in each of the 8 directions (files, ranks,
     * diagonals), the first piece met, whatever its side.
     *
     * @return list<string> sorted
     */
    public static function aligned(string $fen, string $square): array
    {
        $board = Attacks::board($fen);
        $file = \ord($square[0]) - \ord('a');
        $rank = (int) $square[1];
        $found = [];
        foreach (self::DIRECTIONS as [$df, $dr]) {
            for ($step = 1; $step < 8; ++$step) {
                $f = $file + $df * $step;
                $r = $rank + $dr * $step;
                if ($f < 0 || $f > 7 || $r < 1 || $r > 8) {
                    break;
                }
                $at = \chr(\ord('a') + $f).$r;
                if (isset($board[$at])) {
                    $found[] = $at;
                    break;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * @param 'w'|'b'                $color
     * @param callable(string): bool $keep
     *
     * @return list<string>
     */
    private static function of(string $fen, string $color, callable $keep): array
    {
        $found = [];
        foreach (Attacks::board($fen) as $square => $piece) {
            if ('k' !== strtolower($piece) && ('w' === $color) === ctype_upper($piece) && $keep((string) $square)) {
                $found[] = (string) $square;
            }
        }
        sort($found);

        return $found;
    }
}
