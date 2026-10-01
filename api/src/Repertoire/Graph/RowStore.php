<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use App\Entity\Repertoire\Move;
use App\Entity\Repertoire\Position;
use App\Entity\Repertoire\Repertoire;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The raw rows of a repertoire's graph, in SQL, for the operations that move many of them at once
 * ({@see GraphEditor}: deletion, trash, restoration, import, undo). Rows travel as JSON-safe
 * arrays ({@see self::snapshot()}): UUIDs as RFC 4122, the FEN hash in hexadecimal, NAGs as their
 * JSON text. Entities already loaded for the rows deleted are detached: callers read the rows, not
 * entities, afterwards.
 *
 * @phpstan-type Rows array{positions: list<array<string, mixed>>, moves: list<array<string, mixed>>, external?: array<string, string>}
 *                   external: FEN of the positions the moves come from or go to that are not in the rows
 */
final readonly class RowStore
{
    /** Rows per multi-row statement. */
    public const BATCH = 500;

    public function __construct(
        private Connection $connection,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @param list<string> $positionIds
     * @param list<string> $moveIds
     *
     * @return Rows
     */
    public function snapshot(array $positionIds, array $moveIds): array
    {
        return [
            'positions' => $this->rows('SELECT id, fen, fen_hash, turn, depth, created_at FROM repertoire_position WHERE id IN (?)', $positionIds),
            'moves' => $this->rows('SELECT id, from_position_id, to_position_id, uci, san, role, sort_order, comment, nags, canonical, created_at FROM repertoire_move WHERE id IN (?)', $moveIds),
        ];
    }

    /**
     * Deletes moves, then positions.
     *
     * @param list<string> $moveIds
     * @param list<string> $positionIds
     */
    public function delete(array $moveIds, array $positionIds): void
    {
        foreach ($moveIds as $id) {
            $loaded = $this->entityManager->getUnitOfWork()->tryGetById($id, Move::class);
            if ($loaded instanceof Move) {
                $this->entityManager->detach($loaded);
            }
        }
        foreach ($positionIds as $id) {
            $loaded = $this->entityManager->getUnitOfWork()->tryGetById($id, Position::class);
            if ($loaded instanceof Position) {
                $this->entityManager->detach($loaded);
            }
        }
        foreach (array_chunk($moveIds, self::BATCH) as $chunk) {
            $this->connection->executeStatement('DELETE FROM repertoire_move WHERE id IN (?)', [self::binaries($chunk)], [ArrayParameterType::BINARY]);
        }
        foreach (array_chunk($positionIds, self::BATCH) as $chunk) {
            $this->connection->executeStatement('DELETE FROM repertoire_position WHERE id IN (?)', [self::binaries($chunk)], [ArrayParameterType::BINARY]);
        }
    }

    /**
     * Inserts rows with their ids. $exact: the rows come back as they were (undo), canonical moves
     * included (the move chosen meanwhile gives way); otherwise every move is inserted
     * non-canonical and the next indexing chooses.
     *
     * @param Rows $rows
     *
     * @return array{positions: list<string>, moves: list<string>}
     */
    public function insert(Repertoire $repertoire, array $rows, bool $exact = true): array
    {
        $inserted = ['positions' => [], 'moves' => []];
        $positionRows = [];
        foreach ($rows['positions'] as $row) {
            $id = self::string($row['id'] ?? null);
            $positionRows[] = [
                Uuid::fromString($id)->toBinary(),
                $repertoire->getId()->toBinary(),
                $repertoire->getUser()->getId()->toBinary(),
                self::string($row['fen'] ?? null),
                (string) hex2bin(self::string($row['fen_hash'] ?? null)),
                self::string($row['turn'] ?? null),
                is_numeric($row['depth'] ?? null) ? (int) $row['depth'] : 0,
                self::string($row['created_at'] ?? null),
            ];
            $inserted['positions'][] = $id;
        }
        $this->insertRows('repertoire_position', ['id', 'repertoire_id', 'user_id', 'fen', 'fen_hash', 'turn', 'depth', 'created_at'], $positionRows);

        $moveRows = [];
        $canonical = [];
        foreach ($rows['moves'] as $row) {
            $id = self::string($row['id'] ?? null);
            $moveRows[] = [
                Uuid::fromString($id)->toBinary(),
                $repertoire->getId()->toBinary(),
                Uuid::fromString(self::string($row['from_position_id'] ?? null))->toBinary(),
                Uuid::fromString(self::string($row['to_position_id'] ?? null))->toBinary(),
                self::string($row['uci'] ?? null),
                self::string($row['san'] ?? null),
                self::string($row['role'] ?? null),
                is_numeric($row['sort_order'] ?? null) ? (int) $row['sort_order'] : 0,
                \is_string($row['comment'] ?? null) ? $row['comment'] : null,
                \is_string($row['nags'] ?? null) ? $row['nags'] : '[]',
                0,
                self::string($row['created_at'] ?? null),
            ];
            if ($exact && \in_array($row['canonical'] ?? 0, [1, '1', true], true)) {
                $canonical[] = $id;
            }
            $inserted['moves'][] = $id;
        }
        $this->insertRows('repertoire_move', ['id', 'repertoire_id', 'from_position_id', 'to_position_id', 'uci', 'san', 'role', 'sort_order', 'comment', 'nags', 'canonical', 'created_at'], $moveRows);

        foreach (array_chunk($canonical, self::BATCH) as $chunk) {
            $this->connection->executeStatement(
                'UPDATE repertoire_move SET canonical = 0 WHERE to_position_id IN (SELECT to_position_id FROM (SELECT to_position_id FROM repertoire_move WHERE id IN (?)) AS restored)',
                [self::binaries($chunk)],
                [ArrayParameterType::BINARY],
            );
            $this->connection->executeStatement('UPDATE repertoire_move SET canonical = 1 WHERE id IN (?)', [self::binaries($chunk)], [ArrayParameterType::BINARY]);
        }

        return $inserted;
    }

    /**
     * @param array<string, int> $orders move id => sort order
     */
    public function setOrders(array $orders): void
    {
        foreach ($orders as $id => $order) {
            $this->connection->executeStatement('UPDATE repertoire_move SET sort_order = ? WHERE id = ?', [$order, Uuid::fromString($id)->toBinary()], [ParameterType::INTEGER, ParameterType::BINARY]);
        }
    }

    /**
     * @param list<int> $nags
     */
    public function setAnnotation(string $moveId, ?string $comment, array $nags): void
    {
        $this->connection->executeStatement(
            'UPDATE repertoire_move SET comment = ?, nags = ? WHERE id = ?',
            [$comment, json_encode($nags, \JSON_THROW_ON_ERROR), Uuid::fromString($moveId)->toBinary()],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
        );
    }

    /**
     * @return array<string, string> normalized FEN => position id
     */
    public function positionIdsByFen(Repertoire $repertoire): array
    {
        $ids = [];
        foreach ($this->connection->iterateAssociative('SELECT id, fen FROM repertoire_position WHERE repertoire_id = ?', [$repertoire->getId()->toBinary()], [ParameterType::BINARY]) as $row) {
            $ids[self::string($row['fen'])] = Uuid::fromBinary(self::string($row['id']))->toRfc4122();
        }

        return $ids;
    }

    /**
     * Multi-row INSERTs, {@see self::BATCH} rows at a time.
     *
     * @param list<string>            $columns
     * @param list<list<scalar|null>> $rows
     */
    public function insertRows(string $table, array $columns, array $rows): void
    {
        $tuple = '('.implode(', ', array_fill(0, \count($columns), '?')).')';
        foreach (array_chunk($rows, self::BATCH) as $chunk) {
            $this->connection->executeStatement(
                sprintf('INSERT INTO %s (%s) VALUES %s', $table, implode(', ', $columns), implode(', ', array_fill(0, \count($chunk), $tuple))),
                array_merge(...$chunk),
            );
        }
    }

    /**
     * Rows as JSON-safe arrays: binary columns in hexadecimal, UUIDs as RFC 4122.
     *
     * @param list<string> $ids
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $sql, array $ids): array
    {
        $rows = [];
        foreach (array_chunk($ids, self::BATCH) as $chunk) {
            foreach ($this->connection->fetchAllAssociative($sql, [self::binaries($chunk)], [ArrayParameterType::BINARY]) as $row) {
                foreach (['id', 'from_position_id', 'to_position_id'] as $column) {
                    if (isset($row[$column])) {
                        $row[$column] = Uuid::fromBinary(self::string($row[$column]))->toRfc4122();
                    }
                }
                if (isset($row['fen_hash'])) {
                    $row['fen_hash'] = bin2hex(self::string($row['fen_hash']));
                }
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    public static function binaries(array $ids): array
    {
        return array_map(static fn (string $id): string => Uuid::fromString($id)->toBinary(), $ids);
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \LogicException('Row out of shape.');
    }
}
