<?php

declare(strict_types=1);

namespace App\Blindfold\Recall;

use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use Random\Randomizer;

/**
 * Random legal positions for the generated levels (docs/BLINDFOLD_RECALL.md): both kings and the
 * level's pieces ({@see RecallRules::LEVELS}) on random squares, no pawn on the first or last rank,
 * kings never side by side, neither king in check; White to move.
 */
final readonly class PositionGenerator
{
    private const TOKEN_PATTERN = '/^(\?)?([KQRBNPkqrbnp]+)$/';

    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * @param list<string> $pieces the level's pieces besides the kings
     *
     * @return string a full FEN, White to move
     */
    public function generate(array $pieces): string
    {
        for ($try = 0; $try < RecallRules::GENERATION_TRIES; ++$try) {
            $squares = $this->place($pieces);
            if (null === $squares) {
                continue;
            }
            $fen = Placement::fromSquares($squares).' w - - 0 1';
            try {
                // Refuses a side not to move in check, kings side by side included.
                $rules = Rules::fromFen($fen);
            } catch (InvalidPositionException) {
                continue;
            }
            if (!$rules->inCheck()) {
                return $fen;
            }
        }

        throw new \RuntimeException('No legal position could be generated.');
    }

    /**
     * @param list<string> $pieces
     *
     * @return array<string, string>|null square => FEN piece letter, null when a piece found no room
     */
    private function place(array $pieces): ?array
    {
        $squares = [];
        foreach (['K', 'k'] as $king) {
            $square = $this->square($squares, false, null);
            if (null === $square) {
                return null;
            }
            $squares[$square] = $king;
        }
        foreach ($pieces as $token) {
            if (1 !== preg_match(self::TOKEN_PATTERN, $token, $m)) {
                throw new \InvalidArgumentException(\sprintf('Invalid piece token "%s".', $token));
            }
            $white = '' === $m[1] ? null : 0 === $this->randomizer->getInt(0, 1);
            // Bishops of one token stand on squares of both colours.
            $bishopColour = null;
            foreach (str_split($m[2]) as $letter) {
                $piece = null === $white ? $letter : ($white ? strtoupper($letter) : strtolower($letter));
                $bishop = 'b' === strtolower($piece);
                $square = $this->square($squares, 'p' === strtolower($piece), $bishop && null !== $bishopColour ? 1 - $bishopColour : null);
                if (null === $square) {
                    return null;
                }
                $squares[$square] = $piece;
                if ($bishop) {
                    $bishopColour = self::colour($square);
                }
            }
        }

        return $squares;
    }

    /**
     * A random empty square: on ranks 2 to 7 for a pawn, of the given colour (0 dark, 1 light) when
     * one is asked.
     *
     * @param array<string, string> $taken
     */
    private function square(array $taken, bool $pawn, ?int $colour): ?string
    {
        $free = [];
        foreach (range('a', 'h') as $file) {
            foreach (range($pawn ? 2 : 1, $pawn ? 7 : 8) as $rank) {
                $square = $file.$rank;
                if (!isset($taken[$square]) && (null === $colour || self::colour($square) === $colour)) {
                    $free[] = $square;
                }
            }
        }

        return [] === $free ? null : $free[$this->randomizer->getInt(0, \count($free) - 1)];
    }

    /** 0 for a dark square (a1), 1 for a light one. */
    private static function colour(string $square): int
    {
        return (\ord($square[0]) - \ord('a') + (int) $square[1] + 1) % 2;
    }
}
