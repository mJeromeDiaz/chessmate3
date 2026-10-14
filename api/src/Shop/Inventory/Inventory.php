<?php

declare(strict_types=1);

namespace App\Shop\Inventory;

use App\Enum\Shop\ItemType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * What a player holds (docs/SHOP.md): items by quantity, freezes one by one with their days left.
 * Plain SQL, in the caller's transaction.
 *
 * @phpstan-type FreezeView array{id: string, item: string, days: int, daysLeft: int, acquiredAt: string}
 */
final readonly class Inventory
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    /**
     * @return array<string, int> quantity by item type value (freezes: how many still cover a day)
     */
    public function items(Uuid $userId): array
    {
        $items = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT item, quantity FROM shop_inventory_item WHERE user_id = ? AND quantity > 0',
            [$userId->toBinary()],
            [ParameterType::BINARY],
        ) as $row) {
            if (\is_string($row['item']) && is_numeric($row['quantity'])) {
                $items[$row['item']] = (int) $row['quantity'];
            }
        }
        foreach ($this->freezes($userId) as $freeze) {
            $items[$freeze['item']] = ($items[$freeze['item']] ?? 0) + 1;
        }

        return $items;
    }

    /**
     * The freezes that still cover a day, the shortest first.
     *
     * @return list<FreezeView>
     */
    public function freezes(Uuid $userId): array
    {
        $freezes = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, item, days, days_left, acquired_at FROM shop_freeze WHERE user_id = ? AND days_left > 0 ORDER BY days_left, acquired_at',
            [$userId->toBinary()],
            [ParameterType::BINARY],
        ) as $row) {
            if (\is_string($row['id']) && \is_string($row['item']) && is_numeric($row['days']) && is_numeric($row['days_left']) && \is_string($row['acquired_at'])) {
                $freezes[] = [
                    'id' => Uuid::fromBinary($row['id'])->toRfc4122(),
                    'item' => $row['item'],
                    'days' => (int) $row['days'],
                    'daysLeft' => (int) $row['days_left'],
                    'acquiredAt' => str_replace(' ', 'T', $row['acquired_at']).'Z',
                ];
            }
        }

        return $freezes;
    }

    /**
     * Adds $quantity of an item: freezes one by one, the rest as a count.
     */
    public function add(Uuid $userId, ItemType $item, int $quantity, \DateTimeImmutable $now): void
    {
        $days = $item->freezeDays();
        if (null !== $days) {
            for ($i = 0; $i < $quantity; ++$i) {
                $this->connection->executeStatement(
                    'INSERT INTO shop_freeze (id, user_id, item, days, days_left, acquired_at) VALUES (?, ?, ?, ?, ?, ?)',
                    [Uuid::v7()->toBinary(), $userId->toBinary(), $item->value, $days, $days, $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
                    [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER],
                );
            }

            return;
        }
        $this->connection->executeStatement(
            'INSERT INTO shop_inventory_item (user_id, item, quantity) VALUES (?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE quantity = shop_inventory_item.quantity + new.quantity',
            [$userId->toBinary(), $item->value, $quantity],
            [ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER],
        );
    }

    /**
     * Spends one of an item held by quantity (not a freeze: they cover days, Shop\Freeze\FreezeStock).
     *
     * @return bool false when the player has none (nothing spent)
     */
    public function consume(Uuid $userId, ItemType $item): bool
    {
        if (null !== $item->freezeDays()) {
            throw new \LogicException('Freezes are spent by the day.');
        }

        return 1 === $this->connection->executeStatement(
            'UPDATE shop_inventory_item SET quantity = quantity - 1 WHERE user_id = ? AND item = ? AND quantity > 0',
            [$userId->toBinary(), $item->value],
            [ParameterType::BINARY, ParameterType::STRING],
        );
    }
}
