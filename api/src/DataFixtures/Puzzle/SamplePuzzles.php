<?php

declare(strict_types=1);

namespace App\DataFixtures\Puzzle;

use App\Entity\Catalog\Puzzle;

/**
 * 50 real Lichess puzzles in the exact format of the Lichess CSV export (with its header line), so
 * that tests and dev fixtures don't depend on the full import, and the import SQL of
 * docs/PUZZLE_IMPORT.md can be tried on a small file.
 *
 * Sampled from the official dataset mirror (huggingface.co/datasets/Lichess/chess-puzzles, row
 * viewer API, all columns real): 10 mates in 1 (4 with several mating moves), 5 promotions, both
 * colours, ratings 399–2833, ~40 themes, and 2 puzzles below the quality thresholds.
 */
final class SamplePuzzles
{
    public const CSV = __DIR__.'/data/puzzles.csv';

    /**
     * @return list<Puzzle>
     */
    public static function create(): array
    {
        $puzzles = [];
        foreach (self::rows() as $row) {
            $puzzles[] = new Puzzle(
                lichessId: $row[0],
                fen: $row[1],
                moves: $row[2],
                rating: (int) $row[3],
                ratingDeviation: (int) $row[4],
                popularity: (int) $row[5],
                nbPlays: (int) $row[6],
                themes: explode(' ', $row[7]),
                gameUrl: $row[8],
                openingTags: '' === ($row[9] ?? '') ? null : explode(' ', $row[9]),
            );
        }

        return $puzzles;
    }

    /**
     * @return list<list<string>>
     */
    public static function rows(): array
    {
        $handle = fopen(self::CSV, 'r');
        if (false === $handle) {
            throw new \RuntimeException('Cannot open '.self::CSV);
        }

        $rows = [];
        while (false !== ($row = fgetcsv($handle, escape: ''))) {
            if ('PuzzleId' === $row[0]) {
                continue;
            }
            /** @var list<string> $row */
            $rows[] = $row;
        }
        fclose($handle);

        return $rows;
    }
}
