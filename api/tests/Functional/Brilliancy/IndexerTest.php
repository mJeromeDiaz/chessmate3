<?php

declare(strict_types=1);

namespace App\Tests\Functional\Brilliancy;

use App\Brilliancy\Index\Indexer;
use App\Entity\Catalog\Puzzle;
use App\Tests\Functional\Puzzle\PuzzleWebTestCase;
use Doctrine\DBAL\Connection;

/**
 * The index of « Brillant ou Blunder » (docs/BRILLIANCY.md, § 2) on the sample puzzles: what is
 * indexed, by which ply, and a rebuild that gives the same rows.
 */
final class IndexerTest extends PuzzleWebTestCase
{
    public function testTheSamplePuzzlesAreIndexed(): void
    {
        $indexer = self::getContainer()->get(Indexer::class);
        $indexer->index(array_values(array_map(static fn (Puzzle $puzzle): int => (int) $puzzle->getId(), $this->puzzles)));

        self::assertSame(['brilliant' => 2, 'blunder' => 2], $indexer->counts());
        self::assertSame(
            [['GlnnF', 1], ['Glo2A', 0], ['Glo3V', 1], ['RxOnz', 0]],
            $this->rows(),
            'selectable puzzles only: GlnlT (Bxf7+) is below the quality thresholds',
        );

        // Indexing again replaces, a rebuild gives the same rows.
        $indexer->index([(int) $this->puzzles['GlnnF']->getId()]);
        self::assertSame(['brilliant' => 2, 'blunder' => 2], $indexer->rebuildAll());
        self::assertSame([['GlnnF', 1], ['Glo2A', 0], ['Glo3V', 1], ['RxOnz', 0]], $this->rows());
    }

    public function testARebuildDropsTheRowsOfPuzzlesGone(): void
    {
        $this->catalogConnection()->executeStatement('INSERT INTO brilliancy_position (ply, rating, random_key, puzzle_id) VALUES (1, 1500, 1, 4000000000)');
        $counts = self::getContainer()->get(Indexer::class)->rebuildAll();

        self::assertSame(['brilliant' => 2, 'blunder' => 2], $counts);
    }

    /**
     * @return list<array{string, int}> Lichess id, ply; sorted
     */
    private function rows(): array
    {
        $byId = [];
        foreach ($this->puzzles as $puzzle) {
            $byId[(int) $puzzle->getId()] = $puzzle->getLichessId();
        }
        $rows = [];
        foreach ($this->catalogConnection()->fetchAllAssociative('SELECT puzzle_id, ply FROM brilliancy_position') as $row) {
            $rows[] = [$byId[is_numeric($row['puzzle_id']) ? (int) $row['puzzle_id'] : 0] ?? '?', is_numeric($row['ply']) ? (int) $row['ply'] : -1];
        }
        sort($rows);

        return $rows;
    }

    private function catalogConnection(): Connection
    {
        return self::getContainer()->get('doctrine.dbal.catalog_connection');
    }
}
