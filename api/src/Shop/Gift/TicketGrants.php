<?php

declare(strict_types=1);

namespace App\Shop\Gift;

use App\Enum\Shop\ItemType;
use App\Shop\Inventory\Inventory;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Gift wheel tickets given by an admin (docs/SHOP.md, § 4 quater), to one player or to every
 * active account (neither suspended nor being deleted), with a message shown to the players: the
 * stock and the gifts written in one transaction; a gift to everyone has its journal line
 * (`shop_grant_batch`). Giving only: nothing is taken back.
 *
 * @phpstan-type BatchView array{id: string, item: string, quantity: int, message: string, recipients: int, admin: string|null, createdAt: string}
 * @phpstan-type GrantView array{id: string, item: string, quantity: int, message: string, admin: string|null, everyone: bool, createdAt: string, seenAt: string|null}
 */
final readonly class TicketGrants
{
    public const MAX_QUANTITY = 100;
    public const MESSAGE_LENGTH = 255;
    /** The journal's lines listed. */
    public const BATCH_LIMIT = 20;
    /** The players written per statement in a gift to everyone. */
    private const CHUNK = 1000;
    private const ACTIVE = 'suspended_at IS NULL AND deletion_scheduled_at IS NULL';

    public function __construct(
        private Connection $connection,
        private Inventory $inventory,
        private ClockInterface $clock,
    ) {
    }

    public function toPlayer(Uuid $userId, int $quantity, string $message, Uuid $adminId): void
    {
        $instant = $this->clock->now();
        $now = self::stored($instant);
        $this->connection->transactional(function () use ($userId, $quantity, $message, $adminId, $instant, $now): void {
            $this->inventory->add($userId, ItemType::WheelTicket, $quantity, $instant);
            $this->connection->executeStatement(
                'INSERT INTO shop_item_grant (id, user_id, item, quantity, message, admin_id, batch_id, created_at) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)',
                [Uuid::v7()->toBinary(), $userId->toBinary(), ItemType::WheelTicket->value, $quantity, $message, $adminId->toBinary(), $now],
                [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING],
            );
        });
    }

    /** The active accounts, those a gift to everyone serves. */
    public function recipients(): int
    {
        return (int) self::string($this->connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE '.self::ACTIVE));
    }

    /**
     * @return BatchView
     */
    public function toEveryone(int $quantity, string $message, Uuid $adminId): array
    {
        $now = self::stored($this->clock->now());
        $batchId = Uuid::v7();
        $this->connection->transactional(function () use ($quantity, $message, $adminId, $now, $batchId): void {
            $this->connection->executeStatement(
                'INSERT INTO shop_grant_batch (id, item, quantity, message, recipients, admin_id, created_at) VALUES (?, ?, ?, ?, 0, ?, ?)',
                [$batchId->toBinary(), ItemType::WheelTicket->value, $quantity, $message, $adminId->toBinary(), $now],
                [ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING],
            );
            /** @var list<string> $users binary ids */
            $users = $this->connection->fetchFirstColumn('SELECT id FROM app_user WHERE '.self::ACTIVE.' ORDER BY id');
            foreach (array_chunk($users, self::CHUNK) as $chunk) {
                $this->writeChunk($chunk, $quantity, $message, $adminId, $batchId, $now);
            }
            $this->connection->executeStatement(
                'UPDATE shop_grant_batch SET recipients = ? WHERE id = ?',
                [\count($users), $batchId->toBinary()],
                [ParameterType::INTEGER, ParameterType::BINARY],
            );
        });

        return $this->batches(1)[0] ?? throw new \LogicException('The gift was not written.');
    }

    /**
     * The gifts to everyone, the latest first.
     *
     * @return list<BatchView>
     */
    public function batches(int $limit = self::BATCH_LIMIT): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT b.id, b.item, b.quantity, b.message, b.recipients, b.created_at, a.email AS admin
               FROM shop_grant_batch b LEFT JOIN app_user a ON a.id = b.admin_id
              ORDER BY b.created_at DESC, b.id DESC LIMIT '.max(1, $limit),
        );

        return array_map(static fn (array $row): array => [
            'id' => Uuid::fromBinary(self::string($row['id']))->toRfc4122(),
            'item' => self::string($row['item']),
            'quantity' => (int) self::string($row['quantity']),
            'message' => self::string($row['message']),
            'recipients' => (int) self::string($row['recipients']),
            'admin' => \is_string($row['admin']) ? $row['admin'] : null,
            'createdAt' => self::instant($row['created_at']) ?? '',
        ], $rows);
    }

    /**
     * A player's gifts, the latest first.
     *
     * @return list<GrantView>
     */
    public function ofPlayer(Uuid $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT g.id, g.item, g.quantity, g.message, g.batch_id, g.created_at, g.seen_at, a.email AS admin
               FROM shop_item_grant g LEFT JOIN app_user a ON a.id = g.admin_id
              WHERE g.user_id = ? ORDER BY g.created_at DESC, g.id DESC',
            [$userId->toBinary()],
            [ParameterType::BINARY],
        );

        return array_map(static fn (array $row): array => [
            'id' => Uuid::fromBinary(self::string($row['id']))->toRfc4122(),
            'item' => self::string($row['item']),
            'quantity' => (int) self::string($row['quantity']),
            'message' => self::string($row['message']),
            'admin' => \is_string($row['admin']) ? $row['admin'] : null,
            'everyone' => null !== $row['batch_id'],
            'createdAt' => self::instant($row['created_at']) ?? '',
            'seenAt' => self::instant($row['seen_at']),
        ], $rows);
    }

    /**
     * @param list<string> $users binary ids
     */
    private function writeChunk(array $users, int $quantity, string $message, Uuid $adminId, Uuid $batchId, string $now): void
    {
        $grants = [];
        $stock = [];
        foreach ($users as $user) {
            array_push($grants, Uuid::v7()->toBinary(), $user, ItemType::WheelTicket->value, $quantity, $message, $adminId->toBinary(), $batchId->toBinary(), $now);
            array_push($stock, $user, ItemType::WheelTicket->value, $quantity);
        }
        $this->connection->executeStatement(
            'INSERT INTO shop_item_grant (id, user_id, item, quantity, message, admin_id, batch_id, created_at) VALUES '
                .implode(', ', array_fill(0, \count($users), '(?, ?, ?, ?, ?, ?, ?, ?)')),
            $grants,
            array_merge(...array_fill(0, \count($users), [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING])),
        );
        $this->connection->executeStatement(
            'INSERT INTO shop_inventory_item (user_id, item, quantity) VALUES '
                .implode(', ', array_fill(0, \count($users), '(?, ?, ?)'))
                .' AS new ON DUPLICATE KEY UPDATE quantity = shop_inventory_item.quantity + new.quantity',
            $stock,
            array_merge(...array_fill(0, \count($users), [ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER])),
        );
    }

    /** An instant as stored (UTC). */
    private static function stored(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function string(mixed $value): string
    {
        return \is_scalar($value) ? (string) $value : '';
    }

    private static function instant(mixed $value): ?string
    {
        return \is_string($value) ? str_replace(' ', 'T', $value).'Z' : null;
    }
}
