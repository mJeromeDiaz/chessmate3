<?php

declare(strict_types=1);

namespace App\Shop\Gift;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The gifts of an admin a player has not seen yet (docs/SHOP.md, § 4 quater): their message,
 * shown at the next visit, then marked seen. The items were in the stock from the start.
 *
 * @phpstan-type GiftView array{id: string, item: string, quantity: int, message: string}
 */
final readonly class GiftNotices
{
    /** The ids one call marks seen at most. */
    public const MAX_SEEN = 100;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * The oldest first.
     *
     * @return list<GiftView>
     */
    public function unseen(Uuid $userId): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT id, item, quantity, message FROM shop_item_grant WHERE user_id = ? AND seen_at IS NULL ORDER BY created_at, id',
            [$userId->toBinary()],
            [ParameterType::BINARY],
        );

        return array_map(static fn (array $row): array => [
            'id' => Uuid::fromBinary(\is_string($row['id']) ? $row['id'] : '')->toRfc4122(),
            'item' => \is_string($row['item']) ? $row['item'] : '',
            'quantity' => is_numeric($row['quantity']) ? (int) $row['quantity'] : 0,
            'message' => \is_string($row['message']) ? $row['message'] : '',
        ], $rows);
    }

    /**
     * Marks the player's gifts seen (those of another player, or already seen, are left as they are).
     *
     * @param list<Uuid> $ids
     *
     * @return int the gifts marked now
     */
    public function markSeen(Uuid $userId, array $ids): int
    {
        if ([] === $ids) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'UPDATE shop_item_grant SET seen_at = ? WHERE user_id = ? AND seen_at IS NULL AND id IN (?)',
            [
                $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                $userId->toBinary(),
                array_map(static fn (Uuid $id): string => $id->toBinary(), $ids),
            ],
            [ParameterType::STRING, ParameterType::BINARY, ArrayParameterType::BINARY],
        );
    }
}
