<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Writes back what {@see GraphIndexer} derived and differs from the stored graph: canonical flags
 * and depths (segments: {@see SegmentReconciler}). Updates the graph in memory too. Plain SQL:
 * entities of these rows loaded in the entity manager are stale afterwards.
 */
final class IndexWriter
{
    private const BATCH = 500;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return array{moves: list<string>, positions: list<string>} ids whose derived data changed
     */
    public function write(Graph $graph, Index $index): array
    {
        $cleared = $set = [];
        foreach ($graph->moves() as $move) {
            $canonical = $index->canonical[$move->id] ?? false;
            if ($canonical !== $move->canonical) {
                if ($canonical) {
                    $set[] = $move->id;
                } else {
                    $cleared[] = $move->id;
                }
                $move->canonical = $canonical;
            }
        }
        // Cleared first: the unique index allows one canonical move into each position at a time.
        $this->update('UPDATE repertoire_move SET canonical = 0 WHERE id IN (?)', $cleared);
        $this->update('UPDATE repertoire_move SET canonical = 1 WHERE id IN (?)', $set);

        $depths = [];
        foreach ($graph->positions() as $position) {
            $depth = $index->depth[$position->id] ?? $position->depth;
            if ($depth !== $position->depth) {
                $depths[$depth][] = $position->id;
                $position->depth = $depth;
            }
        }
        $positions = [];
        foreach ($depths as $depth => $ids) {
            $this->update(sprintf('UPDATE repertoire_position SET depth = %d WHERE id IN (?)', $depth), $ids);
            array_push($positions, ...$ids);
        }

        return ['moves' => [...$cleared, ...$set], 'positions' => $positions];
    }

    /**
     * @param list<string> $ids
     */
    private function update(string $sql, array $ids): void
    {
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            $this->connection->executeStatement(
                $sql,
                [array_map(static fn (string $id): string => Uuid::fromString($id)->toBinary(), $chunk)],
                [ArrayParameterType::BINARY],
            );
        }
    }
}
