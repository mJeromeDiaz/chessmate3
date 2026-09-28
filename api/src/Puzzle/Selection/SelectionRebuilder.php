<?php

declare(strict_types=1);

namespace App\Puzzle\Selection;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

/**
 * Maintains the data derived from the `puzzle` table for selection: the `selectable` flag,
 * `puzzle_theme_membership` and `puzzle_theme.puzzle_count`. Plain SQL in id-range chunks, so a
 * full rebuild over 5M puzzles never holds one huge transaction or loads entities.
 */
final class SelectionRebuilder
{
    public const CHUNK_SIZE = 100_000;

    /** Shadow table the full rebuild fills before swapping it in (excluded from the Doctrine schema). */
    public const BUILD_TABLE = 'puzzle_theme_membership_build';

    /**
     * Unfolds the `themes` JSON array into one row per known theme key; unknown keys are dropped.
     * Callers append a WHERE clause on p.id.
     */
    private const SELECT_MEMBERSHIPS = <<<'SQL'
        SELECT DISTINCT t.id, p.rating, p.random_key, p.id
        FROM puzzle p
        CROSS JOIN JSON_TABLE(p.themes, '$[*]' COLUMNS (theme_key VARCHAR(32) PATH '$')) AS jt
        JOIN puzzle_theme t ON t.theme_key = CONVERT(jt.theme_key USING ascii)
        WHERE p.selectable = 1
        SQL;

    public function __construct(private Connection $connection)
    {
    }

    /**
     * Full rebuild after an import or a change of {@see Quality} thresholds.
     *
     * Inserting 20M rows in random order into the clustered (theme_id, rating, random_key,
     * puzzle_id) key thrashes the buffer pool (it did not finish in 10 min on the benchmark), so the
     * rows are appended to a shadow table without a primary key, which InnoDB then builds in one
     * sorted pass; a single atomic RENAME swaps it in. Live selection keeps using the old table
     * until then. DDL commits implicitly: never call this inside a transaction (tests use
     * {@see self::addPuzzles()}).
     *
     * @param (callable(int $done, int $total): void)|null $progress
     */
    public function rebuildAll(?callable $progress = null): void
    {
        $max = $this->connection->fetchOne('SELECT MAX(id) FROM puzzle');
        $maxId = is_numeric($max) ? (int) $max : 0;
        $build = self::BUILD_TABLE;

        $this->connection->executeStatement("DROP TABLE IF EXISTS $build");
        $this->connection->executeStatement("CREATE TABLE $build LIKE puzzle_theme_membership");
        $this->connection->executeStatement("ALTER TABLE $build DROP PRIMARY KEY");

        for ($from = 1; $from <= $maxId; $from += self::CHUNK_SIZE) {
            $to = $from + self::CHUNK_SIZE - 1;
            $this->connection->executeStatement(
                'UPDATE puzzle SET selectable = (popularity >= :pop AND nb_plays >= :plays) WHERE id BETWEEN :from AND :to',
                ['pop' => Quality::MIN_POPULARITY, 'plays' => Quality::MIN_PLAYS, 'from' => $from, 'to' => $to],
            );
            $this->connection->executeStatement(
                "INSERT INTO $build (theme_id, rating, random_key, puzzle_id) ".self::SELECT_MEMBERSHIPS.' AND p.id BETWEEN :from AND :to',
                ['from' => $from, 'to' => $to],
            );

            if (null !== $progress) {
                $progress(min($to, $maxId), $maxId);
            }
        }

        $this->connection->executeStatement("ALTER TABLE $build ADD PRIMARY KEY (theme_id, rating, random_key, puzzle_id)");
        $this->connection->executeStatement("RENAME TABLE puzzle_theme_membership TO puzzle_theme_membership_old, $build TO puzzle_theme_membership");
        $this->connection->executeStatement('DROP TABLE puzzle_theme_membership_old');

        $this->refreshThemeCounts();
    }

    /**
     * Indexes a few freshly inserted puzzles (fixtures, tests). Assumes they are not indexed yet.
     *
     * @param list<int> $puzzleIds
     */
    public function addPuzzles(array $puzzleIds): void
    {
        if ([] === $puzzleIds) {
            return;
        }

        $this->connection->executeStatement(
            'INSERT INTO puzzle_theme_membership (theme_id, rating, random_key, puzzle_id) '.self::SELECT_MEMBERSHIPS.' AND p.id IN (:ids)',
            ['ids' => $puzzleIds],
            ['ids' => ArrayParameterType::INTEGER],
        );
        $this->refreshThemeCounts();
    }

    public function refreshThemeCounts(): void
    {
        $this->connection->executeStatement(<<<'SQL'
            UPDATE puzzle_theme t
            LEFT JOIN (
                SELECT theme_id, COUNT(*) AS c FROM puzzle_theme_membership GROUP BY theme_id
            ) m ON m.theme_id = t.id
            SET t.puzzle_count = COALESCE(m.c, 0)
            SQL);
    }
}
