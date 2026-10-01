<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use App\Chess\Position\PositionKey;
use App\Chess\Rules;
use App\Entity\Repertoire\Repertoire;
use App\Enum\Repertoire\MoveRole;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Loads a repertoire's graph structure with two plain queries (no entity hydration: a few
 * thousand rows are read on every change).
 */
final class GraphLoader
{
    public function __construct(
        private readonly Connection $connection,
    ) {
    }

    public function load(Repertoire $repertoire): Graph
    {
        $id = $repertoire->getId()->toBinary();
        $root = PositionKey::of(Rules::initial()->normalizedFen());
        $rootId = $this->connection->fetchOne(
            'SELECT id FROM repertoire_position WHERE repertoire_id = ? AND fen_hash = ?',
            [$id, $root->hash],
            [ParameterType::BINARY, ParameterType::BINARY],
        );
        if (!\is_string($rootId)) {
            throw new \LogicException('A repertoire always has its initial position.');
        }

        $graph = new Graph(self::uuid($rootId), $repertoire->getColor()->turn());
        foreach ($this->connection->iterateAssociative('SELECT id, turn, depth FROM repertoire_position WHERE repertoire_id = ?', [$id], [ParameterType::BINARY]) as $row) {
            $graph->addPosition(new GraphPosition(self::uuid($row['id']), self::string($row['turn']), self::int($row['depth'])));
        }
        $moves = $this->connection->iterateAssociative(
            'SELECT id, from_position_id, to_position_id, role, sort_order, canonical, segment_id, san FROM repertoire_move WHERE repertoire_id = ?',
            [$id],
            [ParameterType::BINARY],
        );
        foreach ($moves as $row) {
            $graph->addMove(new GraphMove(
                self::uuid($row['id']),
                self::uuid($row['from_position_id']),
                self::uuid($row['to_position_id']),
                MoveRole::from(self::string($row['role'])),
                self::int($row['sort_order']),
                1 === self::int($row['canonical']),
                null === $row['segment_id'] ? null : self::uuid($row['segment_id']),
                self::string($row['san']),
            ));
        }

        return $graph;
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
        return \is_int($value) || (\is_string($value) && ctype_digit($value)) ? (int) $value : throw new \UnexpectedValueException('An integer column was expected.');
    }
}
