<?php

declare(strict_types=1);

namespace App\Brilliancy\Index;

use App\Brilliancy\BrilliancyRules;
use App\Enum\Brilliancy\Kind;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Maintains `brilliancy_position` (docs/BRILLIANCY.md, § 2) from the selectable puzzles: each one is
 * read, its first two moves tried on the board ({@see BrilliancyRules::kinds()}), its rows written.
 * In id-range chunks, each replaced in its own transaction: draws keep reading the rest of the index
 * during a rebuild, and a rebuild that dies halfway leaves an index still usable (rerun it). Plain
 * SQL, no DDL (safe inside the tests' transactions), the catalogue's database only.
 */
final class Indexer
{
    public const CHUNK_SIZE = 10_000;
    private const INSERT_BATCH = 500;

    public function __construct(
        #[Autowire(service: 'doctrine.dbal.catalog_connection')]
        private readonly Connection $connection,
    ) {
    }

    /**
     * Full rebuild, after an import or a rebuild of the selection (`selectable` may have changed).
     *
     * @param (callable(int $done, int $total): void)|null $progress
     *
     * @return array{brilliant: int, blunder: int} the rows indexed
     */
    public function rebuildAll(?callable $progress = null): array
    {
        $max = $this->connection->fetchOne('SELECT MAX(id) FROM puzzle');
        $maxId = is_numeric($max) ? (int) $max : 0;
        for ($from = 1; $from <= $maxId; $from += self::CHUNK_SIZE) {
            $to = $from + self::CHUNK_SIZE - 1;
            $this->connection->transactional(function () use ($from, $to): void {
                $this->connection->executeStatement(
                    'DELETE FROM brilliancy_position WHERE puzzle_id BETWEEN :from AND :to',
                    ['from' => $from, 'to' => $to],
                    ['from' => ParameterType::INTEGER, 'to' => ParameterType::INTEGER],
                );
                $this->insert($this->rows(
                    'id BETWEEN :from AND :to',
                    ['from' => $from, 'to' => $to],
                    ['from' => ParameterType::INTEGER, 'to' => ParameterType::INTEGER],
                ));
            });
            if (null !== $progress) {
                $progress(min($to, $maxId), $maxId);
            }
        }
        // Puzzles deleted beyond the last id.
        $this->connection->executeStatement('DELETE FROM brilliancy_position WHERE puzzle_id > :max', ['max' => $maxId], ['max' => ParameterType::INTEGER]);

        return $this->counts();
    }

    /**
     * (Re)indexes a few puzzles (fixtures, tests).
     *
     * @param list<int> $puzzleIds
     */
    public function index(array $puzzleIds): void
    {
        if ([] === $puzzleIds) {
            return;
        }
        $this->connection->transactional(function () use ($puzzleIds): void {
            $this->connection->executeStatement(
                'DELETE FROM brilliancy_position WHERE puzzle_id IN (:ids)',
                ['ids' => $puzzleIds],
                ['ids' => ArrayParameterType::INTEGER],
            );
            $this->insert($this->rows('id IN (:ids)', ['ids' => $puzzleIds], ['ids' => ArrayParameterType::INTEGER]));
        });
    }

    /**
     * @return array{brilliant: int, blunder: int}
     */
    public function counts(): array
    {
        $counts = [Kind::Brilliant->value => 0, Kind::Blunder->value => 0];
        foreach ($this->connection->fetchAllKeyValue('SELECT ply, COUNT(*) FROM brilliancy_position GROUP BY ply') as $ply => $count) {
            $counts[Kind::fromPly(self::int($ply))->value] = self::int($count);
        }

        return $counts;
    }

    /**
     * The index rows of the selectable puzzles matching $filter.
     *
     * @param array<string, mixed>                              $params
     * @param array<string, ParameterType|ArrayParameterType> $types
     *
     * @return list<array{int, int, int, int}> ply, rating, random_key, puzzle_id
     */
    private function rows(string $filter, array $params, array $types): array
    {
        $rows = [];
        foreach ($this->connection->iterateAssociative(
            "SELECT id, fen, moves, themes, rating, random_key FROM puzzle WHERE selectable = 1 AND $filter",
            $params,
            $types,
        ) as $puzzle) {
            $themes = json_decode(\is_string($puzzle['themes']) ? $puzzle['themes'] : '[]', true);
            $kinds = BrilliancyRules::kinds(
                \is_string($puzzle['fen']) ? $puzzle['fen'] : '',
                explode(' ', \is_string($puzzle['moves']) ? $puzzle['moves'] : ''),
                \is_array($themes) ? array_values(array_filter($themes, 'is_string')) : [],
            );
            foreach ($kinds as $kind) {
                $rows[] = [$kind->ply(), self::int($puzzle['rating']), self::int($puzzle['random_key']), self::int($puzzle['id'])];
            }
        }

        return $rows;
    }

    /**
     * @param list<array{int, int, int, int}> $rows
     */
    private function insert(array $rows): void
    {
        foreach (array_chunk($rows, self::INSERT_BATCH) as $batch) {
            $this->connection->executeStatement(
                'INSERT INTO brilliancy_position (ply, rating, random_key, puzzle_id) VALUES '.implode(', ', array_fill(0, \count($batch), '(?, ?, ?, ?)')),
                array_merge(...$batch),
                array_fill(0, 4 * \count($batch), ParameterType::INTEGER),
            );
        }
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
