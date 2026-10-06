<?php

declare(strict_types=1);

namespace App\Puzzle\Selection;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Maintains the data derived from the `puzzle` table for selection: the `selectable` flag,
 * `puzzle_theme_membership` and `puzzle_theme.puzzle_count`. Plain SQL in id-range chunks, so a
 * full rebuild over 5M puzzles never holds one huge transaction or loads entities. All in the
 * catalogue's database (docs/DEPLOY_OVH.md, § 3).
 */
final class SelectionRebuilder
{
    public const CHUNK_SIZE = 100_000;

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

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.catalog_connection')]
        private Connection $connection,
    )
    {
    }

    /**
     * Full rebuild after an import or a change of {@see Quality} thresholds.
     *
     * In place, to keep the catalogue's database under the shared host's 1 GB (a shadow copy of
     * the table would double its ~240 MB at the peak, docs/DEPLOY_OVH.md, § 3): the table is
     * emptied and loses its primary key, the rows are appended in id chunks, then InnoDB builds the
     * key in one sorted pass (inserting 20M rows in random order into the clustered key thrashes
     * the buffer pool: it did not finish in 10 min on the benchmark). Meanwhile {@see RebuildLock}
     * is held and themed draws refuse ({@see SelectionUnavailableException}); draws without theme
     * read `puzzle` and go on. If the process dies halfway, the table stays without its primary
     * key until the next rebuild: `app:deploy:check` reports it.
     *
     * DDL commits implicitly: never call this inside a transaction (tests use
     * {@see self::addPuzzles()}).
     *
     * @param (callable(int $done, int $total): void)|null $progress
     *
     * @throws \RuntimeException when another rebuild is running
     */
    public function rebuildAll(?callable $progress = null): void
    {
        $lock = new RebuildLock($this->connection);
        if (!$lock->acquire()) {
            throw new \RuntimeException('Another rebuild of the selection index is running.');
        }

        try {
            $max = $this->connection->fetchOne('SELECT MAX(id) FROM puzzle');
            $maxId = is_numeric($max) ? (int) $max : 0;

            $this->connection->executeStatement('TRUNCATE TABLE puzzle_theme_membership');
            if ($this->isComplete()) { // else already dropped by a rebuild that died halfway
                $this->connection->executeStatement('ALTER TABLE puzzle_theme_membership DROP PRIMARY KEY');
            }

            for ($from = 1; $from <= $maxId; $from += self::CHUNK_SIZE) {
                $to = $from + self::CHUNK_SIZE - 1;
                $this->connection->executeStatement(
                    'UPDATE puzzle SET selectable = (popularity >= :pop AND nb_plays >= :plays) WHERE id BETWEEN :from AND :to',
                    ['pop' => Quality::MIN_POPULARITY, 'plays' => Quality::MIN_PLAYS, 'from' => $from, 'to' => $to],
                );
                $this->connection->executeStatement(
                    'INSERT INTO puzzle_theme_membership (theme_id, rating, random_key, puzzle_id) '.self::SELECT_MEMBERSHIPS.' AND p.id BETWEEN :from AND :to',
                    ['from' => $from, 'to' => $to],
                );

                if (null !== $progress) {
                    $progress(min($to, $maxId), $maxId);
                }
            }

            $this->connection->executeStatement('ALTER TABLE puzzle_theme_membership ADD PRIMARY KEY (theme_id, rating, random_key, puzzle_id)');
            $this->refreshThemeCounts();
        } finally {
            $lock->release();
        }
    }

    /**
     * False when a rebuild died before rebuilding the primary key: the table is incomplete.
     */
    public function isComplete(): bool
    {
        return false !== $this->connection->fetchOne(
            "SELECT 1 FROM information_schema.table_constraints WHERE table_schema = DATABASE() AND table_name = 'puzzle_theme_membership' AND constraint_type = 'PRIMARY KEY'",
        );
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
