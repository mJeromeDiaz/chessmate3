<?php

declare(strict_types=1);

namespace App\Chess;

/**
 * Which pieces attack a square, on the board alone (docs/VISION.md, § 3): a pawn its two forward
 * diagonals, a knight its jumps, a king its neighbours, a bishop, rook or queen along its lines up
 * to the first piece met. Pins and checks do not matter (a pinned piece still attacks), nor whose
 * turn it is.
 */
final class Attacks
{
    private const KNIGHT_JUMPS = [[1, 2], [2, 1], [2, -1], [1, -2], [-1, -2], [-2, -1], [-2, 1], [-1, 2]];
    private const KING_STEPS = [[1, 0], [1, 1], [0, 1], [-1, 1], [-1, 0], [-1, -1], [0, -1], [1, -1]];
    private const DIAGONALS = [[1, 1], [-1, 1], [-1, -1], [1, -1]];
    private const LINES = [[1, 0], [0, 1], [-1, 0], [0, -1]];

    /**
     * The squares of the pieces of $color attacking $square.
     *
     * @param 'w'|'b' $color
     *
     * @return list<string> sorted
     */
    public static function attackers(string $fen, string $square, string $color): array
    {
        $board = self::board($fen);
        [$file, $rank] = self::coordinates($square);
        $found = [];
        $own = static fn (?string $piece, string $type): bool => null !== $piece
            && strtolower($piece) === $type
            && ('w' === $color) === ctype_upper($piece);

        // A white pawn attacks upwards: it stands one rank below the square.
        $pawnRank = 'w' === $color ? $rank - 1 : $rank + 1;
        foreach ([-1, 1] as $df) {
            $at = self::name($file + $df, $pawnRank);
            if (null !== $at && $own($board[$at] ?? null, 'p')) {
                $found[] = $at;
            }
        }
        foreach ([[self::KNIGHT_JUMPS, 'n'], [self::KING_STEPS, 'k']] as [$steps, $type]) {
            foreach ($steps as [$df, $dr]) {
                $at = self::name($file + $df, $rank + $dr);
                if (null !== $at && $own($board[$at] ?? null, $type)) {
                    $found[] = $at;
                }
            }
        }
        foreach ([[self::DIAGONALS, 'b'], [self::LINES, 'r']] as [$directions, $type]) {
            foreach ($directions as [$df, $dr]) {
                for ($step = 1; $step < 8; ++$step) {
                    $at = self::name($file + $df * $step, $rank + $dr * $step);
                    if (null === $at) {
                        break;
                    }
                    $piece = $board[$at] ?? null;
                    if (null === $piece) {
                        continue;
                    }
                    if ($own($piece, $type) || $own($piece, 'q')) {
                        $found[] = $at;
                    }
                    break;
                }
            }
        }
        sort($found);

        return $found;
    }

    /**
     * The pieces of a FEN's placement by square.
     *
     * @return array<string, string> e.g. ['e1' => 'K']
     */
    public static function board(string $fen): array
    {
        $board = [];
        foreach (explode('/', explode(' ', trim($fen))[0]) as $index => $row) {
            $file = 0;
            foreach (str_split($row) as $char) {
                if (ctype_digit($char)) {
                    $file += (int) $char;
                    continue;
                }
                $name = self::name($file, 8 - $index);
                if (null !== $name) {
                    $board[$name] = $char;
                }
                ++$file;
            }
        }

        return $board;
    }

    /**
     * @return array{int, int} file 0–7, rank 1–8
     */
    private static function coordinates(string $square): array
    {
        if (1 !== preg_match('/^[a-h][1-8]$/', $square)) {
            throw new \InvalidArgumentException(\sprintf('Not a square: %s.', $square));
        }

        return [\ord($square[0]) - \ord('a'), (int) $square[1]];
    }

    private static function name(int $file, int $rank): ?string
    {
        return $file >= 0 && $file < 8 && $rank >= 1 && $rank <= 8 ? \chr(\ord('a') + $file).$rank : null;
    }
}
