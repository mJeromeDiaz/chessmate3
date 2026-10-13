<?php

declare(strict_types=1);

namespace App\Tests\Functional\Woodpecker;

use App\Entity\Catalog\Puzzle;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Puzzles of an opening family added to the test catalogue (the opening sets, docs/COACH.md).
 *
 * @property EntityManagerInterface $catalog
 */
trait OpeningPuzzlesTrait
{
    /** Black to move: the player has White. */
    private const WHITE_SOLVES = ['r2q3r/pp1n1pk1/2pbbn1B/3pR3/3P3Q/3B4/PPP2PPP/RN4K1 b - - 9 16', 'g7g8 e5g5'];
    /** White to move: the player has Black. */
    private const BLACK_SOLVES = ['1k1r3r/6Q1/1q3p2/p3p3/2pnP1p1/2N3P1/1P4B1/3R1RK1 w - - 2 35', 'g7f6 d4e2'];

    /**
     * Ratings from $rating on, spread over 280 points: a draw reads the next puzzles in (rating,
     * key) order from a random rating, so a single rating would always give the same first ones.
     * Within the default range of a user without puzzle rating (1500: 1050 to 1350) by default.
     *
     * @param array{string, string} $puzzle FEN and moves
     */
    private function addPuzzles(string $prefix, string $family, array $puzzle, int $count, int $rating = 1060): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->catalog->persist(new Puzzle(\sprintf('%s%04d', $prefix, $i), $puzzle[0], $puzzle[1], $rating + ($i * 7) % 280, 80, 95, 5000, ['short'], 'https://lichess.org/abcdefgh', [$family, $family.'_Other_Variation']));
        }
    }
}
