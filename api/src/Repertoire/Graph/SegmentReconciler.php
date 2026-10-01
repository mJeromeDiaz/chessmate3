<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use App\Entity\Repertoire\Repertoire;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Brings the stored segments in line with the ones {@see GraphIndexer} derived (docs/REPERTOIRE.md):
 *
 * - a segment whose first move still starts a segment is kept (its counts updated);
 * - a new start reactivates the archived segment with that first move if there is one (undo, a
 *   branching point removed then restored), otherwise creates a segment, derived from the segment
 *   its first move belonged to (a segment cut in two by a new branching point);
 * - a segment whose first move no longer starts one is archived: merged into the segment that now
 *   holds its first move, or without merge if that move is gone or no longer tested;
 * - every move gets its segment (repertoire_move.segment_id).
 *
 * Plain SQL, only for what changes: a large repertoire has thousands of segments and a change
 * touches a few (App\Entity\Repertoire\Segment entities loaded before are stale afterwards).
 */
final class SegmentReconciler
{
    private const BATCH = 500;

    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @return list<string> ids of the moves whose segment changed
     */
    public function reconcile(Repertoire $repertoire, Graph $graph, Index $index, \DateTimeImmutable $now): array
    {
        $repertoireId = $repertoire->getId()->toBinary();
        $at = $now->format('Y-m-d H:i:s');
        /** @var array<string, array{id: string, moveCount: int, userMoveCount: int}> $active */
        $active = [];
        /** @var array<string, string> $archived key => id of the latest archived segment */
        $archived = [];
        $rows = $this->connection->iterateAssociative(
            'SELECT id, start_move_id, archived_at, move_count, user_move_count FROM repertoire_segment WHERE repertoire_id = ? ORDER BY id',
            [$repertoireId],
            [ParameterType::BINARY],
        );
        foreach ($rows as $row) {
            $id = self::uuid($row['id']);
            $key = null === $row['start_move_id'] ? Index::TRUNK : self::uuid($row['start_move_id']);
            if (null === $row['archived_at']) {
                $active[$key] = ['id' => $id, 'moveCount' => self::int($row['move_count']), 'userMoveCount' => self::int($row['user_move_count'])];
            } else {
                $archived[$key] = $id;
            }
        }

        /** @var array<string, string> $byKey segment key => segment id */
        $byKey = [];
        foreach ($index->segments as $key => $counts) {
            $key = (string) $key;
            $counts = [$counts['moveCount'], $counts['userMoveCount']];
            if (isset($active[$key])) {
                $byKey[$key] = $active[$key]['id'];
                if ($counts !== [$active[$key]['moveCount'], $active[$key]['userMoveCount']]) {
                    $this->connection->executeStatement(
                        'UPDATE repertoire_segment SET move_count = ?, user_move_count = ? WHERE id = ?',
                        [...$counts, Uuid::fromString($byKey[$key])->toBinary()],
                        [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::BINARY],
                    );
                }
            } elseif (isset($archived[$key])) {
                $byKey[$key] = $archived[$key];
                $this->connection->executeStatement(
                    'UPDATE repertoire_segment SET archived_at = NULL, merged_into_segment_id = NULL, move_count = ?, user_move_count = ? WHERE id = ?',
                    [...$counts, Uuid::fromString($byKey[$key])->toBinary()],
                    [ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::BINARY],
                );
            } else {
                $previous = Index::TRUNK === $key ? null : $graph->move($key)?->segmentId;
                $byKey[$key] = Uuid::v7()->toRfc4122();
                $this->connection->insert('repertoire_segment', [
                    'id' => Uuid::fromString($byKey[$key])->toBinary(),
                    'repertoire_id' => $repertoireId,
                    'start_move_id' => Index::TRUNK === $key ? null : Uuid::fromString($key)->toBinary(),
                    'derived_from_segment_id' => null === $previous ? null : Uuid::fromString($previous)->toBinary(),
                    'move_count' => $counts[0],
                    'user_move_count' => $counts[1],
                    'created_at' => $at,
                ], [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING]);
            }
        }
        // Archived after the new ones exist: a merged segment points to its successor.
        foreach ($active as $key => $segment) {
            if (isset($byKey[$key])) {
                continue;
            }
            $holder = Index::TRUNK === $key ? null : ($index->segmentOf[$key] ?? null);
            $this->connection->executeStatement(
                'UPDATE repertoire_segment SET archived_at = ?, merged_into_segment_id = ? WHERE id = ?',
                [$at, null === $holder ? null : Uuid::fromString($byKey[$holder])->toBinary(), Uuid::fromString($segment['id'])->toBinary()],
                [ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY],
            );
        }

        $changes = [];
        foreach ($graph->moves() as $move) {
            $key = $index->segmentOf[$move->id] ?? null;
            $segmentId = null === $key ? null : $byKey[$key];
            if ($segmentId !== $move->segmentId) {
                $changes[$segmentId ?? ''][] = $move->id;
                $move->segmentId = $segmentId;
            }
        }
        $changed = [];
        foreach ($changes as $segmentId => $moveIds) {
            foreach (array_chunk($moveIds, self::BATCH) as $chunk) {
                $this->connection->executeStatement(
                    'UPDATE repertoire_move SET segment_id = ? WHERE id IN (?)',
                    ['' === $segmentId ? null : Uuid::fromString((string) $segmentId)->toBinary(), array_map(static fn (string $id): string => Uuid::fromString($id)->toBinary(), $chunk)],
                    [ParameterType::BINARY, ArrayParameterType::BINARY],
                );
            }
            array_push($changed, ...$moveIds);
        }

        return $changed;
    }

    private static function uuid(mixed $binary): string
    {
        return \is_string($binary) ? Uuid::fromBinary($binary)->toRfc4122() : throw new \UnexpectedValueException('A binary column was expected.');
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('An integer column was expected.');
    }
}
