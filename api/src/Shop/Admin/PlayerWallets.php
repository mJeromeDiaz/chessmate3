<?php

declare(strict_types=1);

namespace App\Shop\Admin;

use App\Shop\Gift\TicketGrants;
use App\Shop\Inventory\Inventory;
use App\Shop\Wallet\Wallets;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * Players' crowns for the admin (docs/SHOP.md): a search, then one player's balance, inventory,
 * freezes, ledger and the items given by an admin (§ 4 quater).
 *
 * @phpstan-import-type FreezeView from Inventory
 * @phpstan-import-type GrantView from TicketGrants
 *
 * @phpstan-type PlayerRow array{id: string, email: string|null, displayName: string|null, handle: string|null, balance: int, spent: int}
 * @phpstan-type LedgerRow array{kind: string, delta: int, balanceAfter: int, offerName: string|null, quantity: int|null, reason: string|null, admin: string|null, createdAt: string}
 * @phpstan-type Detail array{player: PlayerRow, inventory: array<string, int>, freezes: list<FreezeView>, ledger: list<LedgerRow>, grants: list<GrantView>}
 */
final readonly class PlayerWallets
{
    public const LIMIT = 50;
    public const LEDGER_LIMIT = 100;

    public function __construct(
        private Connection $connection,
        private Wallets $wallets,
        private Inventory $inventory,
        private TicketGrants $grants,
    ) {
    }

    /**
     * Players matching $search (email, handle, name), or the richest when it is empty.
     *
     * @return list<PlayerRow>
     */
    public function search(string $search): array
    {
        $sql = 'SELECT u.id, u.email, u.display_name, u.handle, COALESCE(w.balance, 0) AS balance,
                       (SELECT COALESCE(-SUM(l.delta), 0) FROM shop_ledger_entry l WHERE l.user_id = u.id AND l.kind = \'purchase\') AS spent
                  FROM app_user u LEFT JOIN shop_wallet w ON w.user_id = u.id';
        $params = [];
        if ('' !== $search) {
            $sql .= ' WHERE u.email LIKE :search OR u.handle LIKE :search OR u.display_name LIKE :search';
            $params['search'] = '%'.addcslashes($search, '%_\\').'%';
        } else {
            $sql .= ' WHERE w.user_id IS NOT NULL';
        }
        $sql .= ' ORDER BY balance DESC, u.created_at DESC LIMIT '.self::LIMIT;

        return array_map(self::player(...), $this->connection->fetchAllAssociative($sql, $params));
    }

    /**
     * @return Detail|null null for an unknown account
     */
    public function detail(Uuid $userId): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT u.id, u.email, u.display_name, u.handle, COALESCE(w.balance, 0) AS balance,
                    (SELECT COALESCE(-SUM(l.delta), 0) FROM shop_ledger_entry l WHERE l.user_id = u.id AND l.kind = \'purchase\') AS spent
               FROM app_user u LEFT JOIN shop_wallet w ON w.user_id = u.id WHERE u.id = ?',
            [$userId->toBinary()],
            [ParameterType::BINARY],
        );
        if (false === $row) {
            return null;
        }
        $ledger = $this->connection->fetchAllAssociative(
            'SELECT l.kind, l.delta, l.balance_after, l.offer_name, l.quantity, l.reason, l.created_at, a.email AS admin
               FROM shop_ledger_entry l LEFT JOIN app_user a ON a.id = l.admin_id
              WHERE l.user_id = ? ORDER BY l.created_at DESC, l.id DESC LIMIT '.self::LEDGER_LIMIT,
            [$userId->toBinary()],
            [ParameterType::BINARY],
        );

        return [
            'player' => self::player($row),
            'inventory' => $this->inventory->items($userId),
            'freezes' => $this->inventory->freezes($userId),
            'ledger' => array_map(static fn (array $l): array => [
                'kind' => \is_string($l['kind']) ? $l['kind'] : '',
                'delta' => self::int($l['delta']),
                'balanceAfter' => self::int($l['balance_after']),
                'offerName' => \is_string($l['offer_name']) ? $l['offer_name'] : null,
                'quantity' => is_numeric($l['quantity']) ? (int) $l['quantity'] : null,
                'reason' => \is_string($l['reason']) ? $l['reason'] : null,
                'admin' => \is_string($l['admin']) ? $l['admin'] : null,
                'createdAt' => \is_string($l['created_at']) ? str_replace(' ', 'T', $l['created_at']).'Z' : '',
            ], $ledger),
            'grants' => $this->grants->ofPlayer($userId),
        ];
    }

    public function balance(Uuid $userId): int
    {
        return $this->wallets->balance($userId);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return PlayerRow
     */
    private static function player(array $row): array
    {
        return [
            'id' => \is_string($row['id']) ? Uuid::fromBinary($row['id'])->toRfc4122() : '',
            'email' => \is_string($row['email']) ? $row['email'] : null,
            'displayName' => \is_string($row['display_name']) ? $row['display_name'] : null,
            'handle' => \is_string($row['handle']) ? $row['handle'] : null,
            'balance' => self::int($row['balance']),
            'spent' => self::int($row['spent']),
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
