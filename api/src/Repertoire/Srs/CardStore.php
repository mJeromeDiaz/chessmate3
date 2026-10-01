<?php

declare(strict_types=1);

namespace App\Repertoire\Srs;

use App\Enum\Repertoire\CardState;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The rows of repertoire_card, in SQL (docs/REPERTOIRE.md). A card is keyed by its repertoire, its
 * position (FEN hash) and its expected move; its row is created at the first answer, fresh.
 */
final readonly class CardStore
{
    private const COLUMNS = 'id, state, step, stability, difficulty, due, last_review, reps, lapses';

    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * The card, or null while it has never been answered (a fresh card, due at once).
     */
    public function find(Uuid $repertoireId, string $fenHash, string $uci): ?StoredCard
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM repertoire_card WHERE repertoire_id = ? AND fen_hash = ? AND uci = ?',
            [$repertoireId->toBinary(), $fenHash, $uci],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );

        return false === $row ? null : self::hydrate($row);
    }

    /**
     * Locks the card for the current transaction, creating its row, fresh, if it has none. Two
     * first answers at once: the unique key makes the second one wait, then read the first's row.
     */
    public function lock(Uuid $repertoireId, string $fenHash, string $fen, string $uci, \DateTimeImmutable $now): StoredCard
    {
        if (!$this->connection->isTransactionActive()) {
            throw new \LogicException('A card is locked inside a transaction.');
        }
        $fresh = Card::fresh($now);
        $this->connection->executeStatement(
            'INSERT INTO repertoire_card (id, repertoire_id, fen_hash, fen, uci, state, step, due, reps, lapses, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, 0, ?)
             ON DUPLICATE KEY UPDATE id = id',
            [Uuid::v7()->toBinary(), $repertoireId->toBinary(), $fenHash, $fen, $uci, $fresh->state->value, $fresh->step, self::instant($now), self::instant($now)],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
        );
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM repertoire_card WHERE repertoire_id = ? AND fen_hash = ? AND uci = ? FOR UPDATE',
            [$repertoireId->toBinary(), $fenHash, $uci],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING],
        );
        if (false === $row) {
            throw new \LogicException('The card row vanished.');
        }

        return self::hydrate($row);
    }

    public function save(StoredCard $stored): void
    {
        $card = $stored->card;
        $this->connection->executeStatement(
            'UPDATE repertoire_card SET state = ?, step = ?, stability = ?, difficulty = ?, due = ?, last_review = ?, reps = ?, lapses = ? WHERE id = ?',
            [
                $card->state->value, $card->step, $card->stability, $card->difficulty, self::instant($card->due),
                null === $card->lastReview ? null : self::instant($card->lastReview), $stored->reps, $stored->lapses, $stored->id->toBinary(),
            ],
            [
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
                ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::BINARY,
            ],
        );
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): StoredCard
    {
        $state = CardState::from(self::int($row['state']));

        return new StoredCard(
            Uuid::fromBinary(self::string($row['id'])),
            new Card(
                $state,
                null === $row['step'] ? null : self::int($row['step']),
                null === $row['stability'] ? null : self::float($row['stability']),
                null === $row['difficulty'] ? null : self::float($row['difficulty']),
                new \DateTimeImmutable(self::string($row['due'])),
                null === $row['last_review'] ? null : new \DateTimeImmutable(self::string($row['last_review'])),
            ),
            self::int($row['reps']),
            self::int($row['lapses']),
        );
    }

    /** DATETIME column, UTC (the connection's time zone). */
    private static function instant(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('Not a number.');
    }

    private static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : throw new \UnexpectedValueException('Not a number.');
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : throw new \UnexpectedValueException('Not a string.');
    }
}
