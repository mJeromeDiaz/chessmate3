<?php

declare(strict_types=1);

namespace App\Endgame\Drawing;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Endgame\Lesson\Lesson;
use Random\Randomizer;

/**
 * Variants of a lesson's textbook position (docs/ENDGAMES.md, § 4), the same idea on other
 * squares, before the tablebase checks them:
 * - symmetries that keep the theory: the board mirrored a↔h, the colours swapped (board upside
 *   down, white for black, the other side to move), both;
 * - the whole board shifted along the ranks by the lesson's file shifts, pawns kept off the a- and
 *   h-files unless the lesson allows them;
 * - for lessons with a material, its pieces placed at random (the player's side moves).
 *
 * Every variant is a legal position whose side to move has a move. Positions carry no castling
 * right nor en passant square, counters reset.
 */
final readonly class VariantDrawer
{
    /**
     * The symmetric and shifted variants, textbook first, without duplicates.
     *
     * @return list<string> FENs
     */
    public function variants(Lesson $lesson): array
    {
        [$board, $turn] = self::parse($lesson->textbook);
        $variants = [];
        foreach ([0, ...$lesson->fileShifts] as $shift) {
            $shifted = self::shift($board, $shift, 0 !== $shift && $lesson->pawnsOffEdge);
            if (null === $shifted) {
                continue;
            }
            foreach ([[false, false], [true, false], [false, true], [true, true]] as [$mirror, $swap]) {
                $fen = self::fen($mirror ? self::mirror($shifted) : $shifted, $turn, $swap);
                if (!\in_array($fen, $variants, true) && self::playable($fen)) {
                    $variants[] = $fen;
                }
            }
        }

        return $variants;
    }

    /**
     * A position of the lesson's material at random, null when the lesson has none or the draw
     * gave an illegal position (draw again).
     */
    public function random(Lesson $lesson, Randomizer $randomizer): ?string
    {
        if (null === $lesson->material) {
            return null;
        }
        [$mine, $theirs] = explode('-', $lesson->material);
        [, $turn] = self::parse($lesson->textbook);
        $white = 'w' === $turn ? $mine : $theirs;
        $black = 'w' === $turn ? $theirs : $mine;
        $pieces = [...str_split($white), ...str_split(strtolower($black))];

        $board = array_fill(0, 64, null);
        $squares = $randomizer->shuffleArray(range(0, 63));
        $bishopColour = [];
        foreach ($pieces as $piece) {
            do {
                $square = array_pop($squares);
                if (null === $square) {
                    return null;
                }
                $colour = (intdiv($square, 8) + $square % 8) % 2;
                $side = ctype_upper($piece) ? 'w' : 'b';
                $sameColourBishop = 'b' === strtolower($piece) && ($bishopColour[$side] ?? null) === $colour;
                $badPawnRank = 'p' === strtolower($piece) && \in_array(intdiv($square, 8), [0, 7], true);
            } while ($sameColourBishop || $badPawnRank);
            if ('b' === strtolower($piece)) {
                $bishopColour[$side] = $colour;
            }
            $board[$square] = $piece;
        }
        $fen = self::fen($board, $turn, false);

        return self::playable($fen) ? $fen : null;
    }

    /**
     * @return array{array<int, string|null>, string} the board (index = rank × 8 + file, a1 = 0) and the side to move
     */
    private static function parse(string $fen): array
    {
        [$placement, $turn] = explode(' ', $fen);
        $board = array_fill(0, 64, null);
        foreach (explode('/', $placement) as $row => $line) {
            $file = 0;
            foreach (str_split($line) as $char) {
                if (ctype_digit($char)) {
                    $file += (int) $char;
                    continue;
                }
                $board[(7 - $row) * 8 + $file] = $char;
                ++$file;
            }
        }

        return [$board, $turn];
    }

    /**
     * @param array<int, string|null> $board
     */
    private static function fen(array $board, string $turn, bool $swapColours): string
    {
        $rows = [];
        for ($rank = 7; $rank >= 0; --$rank) {
            $line = '';
            $empty = 0;
            for ($file = 0; $file < 8; ++$file) {
                $piece = $board[($swapColours ? 7 - $rank : $rank) * 8 + $file];
                if (null === $piece) {
                    ++$empty;
                    continue;
                }
                $line .= ($empty > 0 ? (string) $empty : '').($swapColours ? (ctype_upper($piece) ? strtolower($piece) : strtoupper($piece)) : $piece);
                $empty = 0;
            }
            $rows[] = $line.($empty > 0 ? (string) $empty : '');
        }
        $side = $swapColours ? ('w' === $turn ? 'b' : 'w') : $turn;

        return implode('/', $rows).' '.$side.' - - 0 1';
    }

    /**
     * @param array<int, string|null> $board
     *
     * @return array<int, string|null>
     */
    private static function mirror(array $board): array
    {
        $mirrored = array_fill(0, 64, null);
        foreach ($board as $square => $piece) {
            $mirrored[intdiv($square, 8) * 8 + 7 - $square % 8] = $piece;
        }

        return $mirrored;
    }

    /**
     * @param array<int, string|null> $board
     *
     * @return array<int, string|null>|null null when a piece leaves the board, or a pawn reaches an edge file it must avoid
     */
    private static function shift(array $board, int $files, bool $pawnsOffEdge): ?array
    {
        $shifted = array_fill(0, 64, null);
        foreach ($board as $square => $piece) {
            if (null === $piece) {
                continue;
            }
            $file = $square % 8 + $files;
            if ($file < 0 || $file > 7 || ($pawnsOffEdge && 'p' === strtolower($piece) && \in_array($file, [0, 7], true))) {
                return null;
            }
            $shifted[intdiv($square, 8) * 8 + $file] = $piece;
        }

        return $shifted;
    }

    /** Legal, the side to move has a move. */
    private static function playable(string $fen): bool
    {
        try {
            return [] !== Rules::fromFen($fen)->legalMoves();
        } catch (InvalidPositionException) {
            return false;
        }
    }
}
