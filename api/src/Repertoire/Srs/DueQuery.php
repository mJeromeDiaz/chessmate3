<?php

declare(strict_types=1);

namespace App\Repertoire\Srs;

use App\Enum\Repertoire\MoveRole;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Which cards are due (docs/REPERTOIRE.md). The active cards are those of the repertoires'
 * reference moves, joined by key (repertoire, position FEN hash, expected move): a move without a
 * card row is new, due at once; a card whose move left the repertoire is not counted.
 */
final readonly class DueQuery
{
    private const JOIN = 'FROM repertoire_move m
        JOIN repertoire_position p ON p.id = m.from_position_id
        LEFT JOIN repertoire_card c ON c.repertoire_id = m.repertoire_id AND c.fen_hash = p.fen_hash AND c.uci = m.uci
        WHERE m.repertoire_id IN (?) AND m.role = ?';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The prepared moves whose card is due or new, the longest overdue first, then the new ones.
     *
     * @param list<Uuid> $repertoireIds
     *
     * @return list<DueMove>
     */
    public function dueMoves(array $repertoireIds, \DateTimeImmutable $now): array
    {
        if ([] === $repertoireIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.repertoire_id, m.id, m.from_position_id, m.segment_id, c.due '.self::JOIN.'
             AND (c.id IS NULL OR c.due <= ?)
             ORDER BY c.due IS NULL, c.due, p.depth, m.id',
            [array_map(static fn (Uuid $id): string => $id->toBinary(), $repertoireIds), MoveRole::Reference->value, self::instant($now)],
            [ArrayParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
        );

        return array_map(static fn (array $row): DueMove => new DueMove(
            Uuid::fromBinary(self::string($row['repertoire_id'])),
            Uuid::fromBinary(self::string($row['id'])),
            Uuid::fromBinary(self::string($row['from_position_id'])),
            null === $row['segment_id'] ? null : Uuid::fromBinary(self::string($row['segment_id'])),
            null === $row['due'] ? null : new \DateTimeImmutable(self::string($row['due'])),
        ), $rows);
    }

    /**
     * The due date of the active cards already answered and due now, by move (RFC 4122).
     *
     * @param list<Uuid> $repertoireIds
     *
     * @return array<string, \DateTimeImmutable>
     */
    public function overdue(array $repertoireIds, \DateTimeImmutable $now): array
    {
        if ([] === $repertoireIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.id, c.due '.self::JOIN.' AND c.due <= ?',
            [array_map(static fn (Uuid $id): string => $id->toBinary(), $repertoireIds), MoveRole::Reference->value, self::instant($now)],
            [ArrayParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
        );
        $overdue = [];
        foreach ($rows as $row) {
            $overdue[Uuid::fromBinary(self::string($row['id']))->toRfc4122()] = new \DateTimeImmutable(self::string($row['due']));
        }

        return $overdue;
    }

    /**
     * Active cards: all of them, the new ones (never answered) and the due ones (answered, due now).
     *
     * @param list<Uuid> $repertoireIds
     *
     * @return array{total: int, new: int, due: int}
     */
    public function counts(array $repertoireIds, \DateTimeImmutable $now): array
    {
        if ([] === $repertoireIds) {
            return ['total' => 0, 'new' => 0, 'due' => 0];
        }
        $row = $this->connection->fetchAssociative(
            'SELECT COUNT(*) total, COALESCE(SUM(c.id IS NULL), 0) new, COALESCE(SUM(c.due <= ?), 0) due '.self::JOIN,
            [self::instant($now), array_map(static fn (Uuid $id): string => $id->toBinary(), $repertoireIds), MoveRole::Reference->value],
            [ParameterType::STRING, ArrayParameterType::BINARY, ParameterType::STRING],
        );
        if (false === $row || !is_numeric($row['total']) || !is_numeric($row['new']) || !is_numeric($row['due'])) {
            throw new \UnexpectedValueException('No counts.');
        }

        return ['total' => (int) $row['total'], 'new' => (int) $row['new'], 'due' => (int) $row['due']];
    }

    /**
     * Active cards by repertoire (RFC 4122): all, new (never answered), learning (learning or
     * relearning steps), review, due now (answered and due).
     *
     * @param list<Uuid> $repertoireIds
     *
     * @return array<string, array{total: int, new: int, learning: int, review: int, due: int}>
     */
    public function countsByRepertoire(array $repertoireIds, \DateTimeImmutable $now): array
    {
        if ([] === $repertoireIds) {
            return [];
        }
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.repertoire_id, COUNT(*) total, COALESCE(SUM(c.id IS NULL), 0) new,
                    COALESCE(SUM(c.state IN (1, 3)), 0) learning, COALESCE(SUM(c.state = 2), 0) review, COALESCE(SUM(c.due <= ?), 0) due '
            .self::JOIN.' GROUP BY m.repertoire_id',
            [self::instant($now), self::binaries($repertoireIds), MoveRole::Reference->value],
            [ParameterType::STRING, ArrayParameterType::BINARY, ParameterType::STRING],
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[Uuid::fromBinary(self::string($row['repertoire_id']))->toRfc4122()] = [
                'total' => self::int($row['total']),
                'new' => self::int($row['new']),
                'learning' => self::int($row['learning']),
                'review' => self::int($row['review']),
                'due' => self::int($row['due']),
            ];
        }

        return $counts;
    }

    /**
     * Active cards by segment (RFC 4122): new and due now.
     *
     * @return array<string, array{new: int, due: int}>
     */
    public function countsBySegment(Uuid $repertoireId, \DateTimeImmutable $now): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT m.segment_id, COALESCE(SUM(c.id IS NULL), 0) new, COALESCE(SUM(c.due <= ?), 0) due '
            .self::JOIN.' AND m.segment_id IS NOT NULL GROUP BY m.segment_id',
            [self::instant($now), [$repertoireId->toBinary()], MoveRole::Reference->value],
            [ParameterType::STRING, ArrayParameterType::BINARY, ParameterType::STRING],
        );
        $counts = [];
        foreach ($rows as $row) {
            $counts[Uuid::fromBinary(self::string($row['segment_id']))->toRfc4122()] = ['new' => self::int($row['new']), 'due' => self::int($row['due'])];
        }

        return $counts;
    }

    /**
     * Due dates of the active cards already answered, due before $until (overdue ones included).
     *
     * @param list<Uuid> $repertoireIds
     *
     * @return list<\DateTimeImmutable>
     */
    public function dueDates(array $repertoireIds, \DateTimeImmutable $until): array
    {
        if ([] === $repertoireIds) {
            return [];
        }
        $dues = $this->connection->fetchFirstColumn(
            'SELECT c.due '.self::JOIN.' AND c.due < ?',
            [self::binaries($repertoireIds), MoveRole::Reference->value, self::instant($until)],
            [ArrayParameterType::BINARY, ParameterType::STRING, ParameterType::STRING],
        );

        return array_map(static fn (mixed $due): \DateTimeImmutable => new \DateTimeImmutable(self::string($due)), $dues);
    }

    /**
     * @param list<Uuid> $ids
     *
     * @return list<string>
     */
    private static function binaries(array $ids): array
    {
        return array_map(static fn (Uuid $id): string => $id->toBinary(), $ids);
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('Not a number.');
    }

    private static function instant(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Not a string.');
    }
}
