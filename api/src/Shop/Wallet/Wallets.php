<?php

declare(strict_types=1);

namespace App\Shop\Wallet;

use App\Enum\Shop\LedgerKind;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Players' crowns (docs/SHOP.md): the balance row, locked for every change, and the ledger entry
 * that explains it. Plain SQL: callers run it in a DBAL transaction (a refusal rolls back without
 * closing the entity manager).
 */
final readonly class Wallets
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /** The balance (0 for a player who never had crowns). */
    public function balance(Uuid $userId): int
    {
        $balance = $this->connection->fetchOne('SELECT balance FROM shop_wallet WHERE user_id = ?', [$userId->toBinary()], [ParameterType::BINARY]);

        return is_numeric($balance) ? (int) $balance : 0;
    }

    /**
     * Locks the player's balance until the transaction ends (created at 0 when missing).
     *
     * @return int the balance
     */
    public function lock(Uuid $userId, \DateTimeImmutable $now): int
    {
        $this->connection->executeStatement(
            'INSERT IGNORE INTO shop_wallet (user_id, balance, updated_at) VALUES (?, 0, ?)',
            [$userId->toBinary(), self::utc($now)],
            [ParameterType::BINARY],
        );
        $balance = $this->connection->fetchOne('SELECT balance FROM shop_wallet WHERE user_id = ? FOR UPDATE', [$userId->toBinary()], [ParameterType::BINARY]);

        return is_numeric($balance) ? (int) $balance : 0;
    }

    /**
     * Moves crowns on a balance locked by {@see lock()}, with its ledger entry.
     *
     * @param array{offerId?: Uuid, offerName?: string, quantity?: int, reason?: string|null, adminId?: Uuid} $details
     *
     * @return int the new balance
     */
    public function move(Uuid $userId, int $lockedBalance, int $delta, LedgerKind $kind, array $details, \DateTimeImmutable $now): int
    {
        $after = $lockedBalance + $delta;
        if ($after < 0) {
            throw new \LogicException('A balance never goes below zero.');
        }
        $this->connection->executeStatement(
            'UPDATE shop_wallet SET balance = ?, updated_at = ? WHERE user_id = ?',
            [$after, self::utc($now), $userId->toBinary()],
            [ParameterType::INTEGER, ParameterType::STRING, ParameterType::BINARY],
        );
        $this->connection->executeStatement(
            'INSERT INTO shop_ledger_entry (id, user_id, kind, delta, balance_after, offer_id, offer_name, quantity, reason, admin_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                Uuid::v7()->toBinary(), $userId->toBinary(), $kind->value, $delta, $after,
                ($details['offerId'] ?? null)?->toBinary(), $details['offerName'] ?? null, $details['quantity'] ?? null,
                $details['reason'] ?? null, ($details['adminId'] ?? null)?->toBinary(), self::utc($now),
            ],
            [
                ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER,
                ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING,
            ],
        );

        return $after;
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
