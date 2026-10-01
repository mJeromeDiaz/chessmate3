<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use App\Entity\Repertoire\Repertoire;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The trash of a repertoire (table repertoire_trash, entity App\Entity\Repertoire\TrashedSuite),
 * in SQL, inside the change that fills or empties it ({@see GraphEditor}). An entry is a suite: a
 * move and everything only reachable through it, as rows with their ids ({@see RowStore}).
 *
 * @phpstan-import-type Rows from RowStore
 *
 * @phpstan-type TrashEntry array{id: string, reason: string, fromFen: string, uci: string, san: string, path: list<string>, positionCount: int, moveCount: int, rows: Rows, createdAt: string}
 */
final readonly class TrashBin
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * Stores an entry with its id (a new one, or one taken out again when undoing a restoration).
     *
     * @param TrashEntry $entry
     */
    public function insert(Repertoire $repertoire, array $entry): void
    {
        $this->connection->insert('repertoire_trash', [
            'id' => Uuid::fromString($entry['id'])->toBinary(),
            'repertoire_id' => $repertoire->getId()->toBinary(),
            'reason' => $entry['reason'],
            'from_fen' => $entry['fromFen'],
            'uci' => $entry['uci'],
            'san' => $entry['san'],
            'path' => json_encode($entry['path'], \JSON_THROW_ON_ERROR),
            'position_count' => $entry['positionCount'],
            'move_count' => $entry['moveCount'],
            'suite_rows' => json_encode($entry['rows'], \JSON_THROW_ON_ERROR),
            'created_at' => $entry['createdAt'],
        ]);
    }

    /**
     * @return TrashEntry|null
     */
    public function find(Repertoire $repertoire, string $id): ?array
    {
        if (!Uuid::isValid($id)) {
            return null;
        }
        $row = $this->connection->fetchAssociative(
            'SELECT id, reason, from_fen, uci, san, path, position_count, move_count, suite_rows, created_at FROM repertoire_trash WHERE id = ? AND repertoire_id = ?',
            [Uuid::fromString($id)->toBinary(), $repertoire->getId()->toBinary()],
            [ParameterType::BINARY, ParameterType::BINARY],
        );
        if (false === $row) {
            return null;
        }
        $path = json_decode(self::string($row['path']), true, 8, \JSON_THROW_ON_ERROR);
        $rows = json_decode(self::string($row['suite_rows']), true, 16, \JSON_THROW_ON_ERROR);
        if (!\is_array($path) || !\is_array($rows) || !\is_array($rows['positions'] ?? null) || !\is_array($rows['moves'] ?? null)) {
            throw new \UnexpectedValueException('Trash entry out of shape.');
        }

        return [
            'id' => (string) Uuid::fromBinary(self::string($row['id'])),
            'reason' => self::string($row['reason']),
            'fromFen' => self::string($row['from_fen']),
            'uci' => self::string($row['uci']),
            'san' => self::string($row['san']),
            'path' => array_values(array_filter($path, 'is_string')),
            'positionCount' => is_numeric($row['position_count']) ? (int) $row['position_count'] : 0,
            'moveCount' => is_numeric($row['move_count']) ? (int) $row['move_count'] : 0,
            'rows' => [
                'positions' => self::rowList($rows['positions']),
                'moves' => self::rowList($rows['moves']),
                'external' => self::external($rows['external'] ?? []),
            ],
            'createdAt' => self::string($row['created_at']),
        ];
    }

    public function remove(string $id): void
    {
        $this->connection->executeStatement('DELETE FROM repertoire_trash WHERE id = ?', [Uuid::fromString($id)->toBinary()], [ParameterType::BINARY]);
    }

    /**
     * @param array<mixed> $rows
     *
     * @return list<array<string, mixed>>
     */
    private static function rowList(array $rows): array
    {
        $list = [];
        foreach ($rows as $row) {
            if (\is_array($row)) {
                $list[] = array_combine(array_map('strval', array_keys($row)), array_values($row));
            }
        }

        return $list;
    }

    /**
     * @return array<string, string>
     */
    private static function external(mixed $fens): array
    {
        $external = [];
        foreach (\is_array($fens) ? $fens : [] as $id => $fen) {
            if (\is_string($fen)) {
                $external[(string) $id] = $fen;
            }
        }

        return $external;
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Trash entry out of shape.');
    }
}
