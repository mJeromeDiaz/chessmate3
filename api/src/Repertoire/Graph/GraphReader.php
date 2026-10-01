<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use App\Entity\Repertoire\Repertoire;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Reads a repertoire's graph for the API as plain arrays (a large repertoire has thousands of
 * positions: no entity hydration). Ids as RFC 4122 strings.
 *
 * @phpstan-type PositionRow array{id: string, fen: string, turn: string, depth: int, opening: array{eco: string, name: string}|null}
 * @phpstan-type MoveRow array{id: string, from: string, to: string, uci: string, san: string, role: string, sortOrder: int, comment: string|null, nags: list<int>, canonical: bool, segmentId: string|null}
 * @phpstan-type SegmentRow array{id: string, startMoveId: string|null, moveCount: int, userMoveCount: int}
 */
final class GraphReader
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    /**
     * @param list<string>|null $ids null: all of the repertoire
     *
     * @return list<PositionRow>
     */
    public function positions(Repertoire $repertoire, ?array $ids = null): array
    {
        $rows = $this->select(
            'SELECT p.id, p.fen, p.turn, p.depth, o.eco, o.name FROM repertoire_position p
             LEFT JOIN repertoire_opening o ON o.epd_hash = p.fen_hash
             WHERE p.repertoire_id = ?',
            'p.id',
            $repertoire,
            $ids,
        );

        return array_map(static fn (array $row): array => [
            'id' => self::uuid($row['id']),
            'fen' => self::string($row['fen']),
            'turn' => self::string($row['turn']),
            'depth' => self::int($row['depth']),
            'opening' => null === $row['eco'] ? null : ['eco' => self::string($row['eco']), 'name' => self::string($row['name'])],
        ], $rows);
    }

    /**
     * @param list<string>|null $ids null: all of the repertoire
     *
     * @return list<MoveRow>
     */
    public function moves(Repertoire $repertoire, ?array $ids = null): array
    {
        $rows = $this->select(
            'SELECT id, from_position_id, to_position_id, uci, san, role, sort_order, comment, nags, canonical, segment_id FROM repertoire_move WHERE repertoire_id = ?',
            'id',
            $repertoire,
            $ids,
        );

        return array_map(static function (array $row): array {
            $nags = json_decode(self::string($row['nags']), true);

            return [
                'id' => self::uuid($row['id']),
                'from' => self::uuid($row['from_position_id']),
                'to' => self::uuid($row['to_position_id']),
                'uci' => self::string($row['uci']),
                'san' => self::string($row['san']),
                'role' => self::string($row['role']),
                'sortOrder' => self::int($row['sort_order']),
                'comment' => null === $row['comment'] ? null : self::string($row['comment']),
                'nags' => \is_array($nags) ? array_values(array_filter($nags, 'is_int')) : [],
                'canonical' => 1 === self::int($row['canonical']),
                'segmentId' => null === $row['segment_id'] ? null : self::uuid($row['segment_id']),
            ];
        }, $rows);
    }

    /**
     * Active segments.
     *
     * @param list<string>|null $ids null: all of the repertoire
     *
     * @return list<SegmentRow>
     */
    public function segments(Repertoire $repertoire, ?array $ids = null): array
    {
        $rows = $this->select(
            'SELECT id, start_move_id, move_count, user_move_count FROM repertoire_segment WHERE repertoire_id = ? AND archived_at IS NULL',
            'id',
            $repertoire,
            $ids,
        );

        return array_map(static fn (array $row): array => [
            'id' => self::uuid($row['id']),
            'startMoveId' => null === $row['start_move_id'] ? null : self::uuid($row['start_move_id']),
            'moveCount' => self::int($row['move_count']),
            'userMoveCount' => self::int($row['user_move_count']),
        ], $rows);
    }

    public function rootId(Repertoire $repertoire): string
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM repertoire_position WHERE repertoire_id = ? AND depth = 0',
            [$repertoire->getId()->toBinary()],
            [ParameterType::BINARY],
        );

        return self::uuid($id);
    }

    /**
     * @param list<string>|null $ids
     *
     * @return list<array<string, mixed>>
     */
    private function select(string $sql, string $idColumn, Repertoire $repertoire, ?array $ids): array
    {
        if (null === $ids) {
            return $this->connection->fetchAllAssociative($sql, [$repertoire->getId()->toBinary()], [ParameterType::BINARY]);
        }
        $rows = [];
        foreach (array_chunk($ids, 500) as $chunk) {
            array_push($rows, ...$this->connection->fetchAllAssociative(
                $sql.' AND '.$idColumn.' IN (?)',
                [$repertoire->getId()->toBinary(), array_map(static fn (string $id): string => Uuid::fromString($id)->toBinary(), $chunk)],
                [ParameterType::BINARY, ArrayParameterType::BINARY],
            ));
        }

        return $rows;
    }

    private static function uuid(mixed $binary): string
    {
        return Uuid::fromBinary(self::string($binary))->toRfc4122();
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('A string column was expected.');
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('An integer column was expected.');
    }
}
