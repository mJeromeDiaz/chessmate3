<?php

declare(strict_types=1);

namespace App\Blindfold\Recall;

/**
 * A FEN's piece placement read as squares, and written back. Pure.
 */
final class Placement
{
    public const PIECE_PATTERN = '/^[KQRBNPkqrbnp]$/';
    public const SQUARE_PATTERN = '/^[a-h][1-8]$/';

    /**
     * @return array<string, string> square ("e4") => FEN piece letter, a8 to h1
     */
    public static function squares(string $fen): array
    {
        $ranks = explode('/', explode(' ', trim($fen))[0]);
        if (8 !== \count($ranks)) {
            throw new \InvalidArgumentException('A placement has 8 ranks.');
        }
        $squares = [];
        foreach ($ranks as $index => $rank) {
            $file = 0;
            foreach (str_split($rank) as $char) {
                if (ctype_digit($char)) {
                    $file += (int) $char;
                    continue;
                }
                if (1 !== preg_match(self::PIECE_PATTERN, $char) || $file > 7) {
                    throw new \InvalidArgumentException('Invalid placement.');
                }
                $squares[\chr(\ord('a') + $file).(8 - $index)] = $char;
                ++$file;
            }
            if (8 !== $file) {
                throw new \InvalidArgumentException('Each rank has 8 squares.');
            }
        }

        return $squares;
    }

    /**
     * @param array<string, string> $squares square => FEN piece letter
     */
    public static function fromSquares(array $squares): string
    {
        $ranks = [];
        for ($rank = 8; $rank >= 1; --$rank) {
            $row = '';
            $empty = 0;
            foreach (range('a', 'h') as $file) {
                $piece = $squares[$file.$rank] ?? null;
                if (null === $piece) {
                    ++$empty;
                    continue;
                }
                $row .= ($empty > 0 ? (string) $empty : '').$piece;
                $empty = 0;
            }
            $ranks[] = $row.($empty > 0 ? (string) $empty : '');
        }

        return implode('/', $ranks);
    }
}
