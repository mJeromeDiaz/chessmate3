<?php

declare(strict_types=1);

namespace App\Shop\Freeze;

use App\Gamification\Streak\FreezeStockInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The store's freezes covering missed days of the daily streak (docs/SHOP.md): a gap is covered
 * by the smallest freeze long enough, else by the shortest ones together (two 1-day freezes
 * cover 2 days); what a freeze does not use stays for later.
 */
final readonly class FreezeStock implements FreezeStockInterface
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function available(Uuid $userId): int
    {
        $days = $this->connection->fetchOne('SELECT COALESCE(SUM(days_left), 0) FROM shop_freeze WHERE user_id = ?', [$userId->toBinary()], [ParameterType::BINARY]);

        return is_numeric($days) ? (int) $days : 0;
    }

    public function consume(Uuid $userId, int $days, \DateTimeImmutable $now): bool
    {
        if ($days < 1) {
            return true;
        }
        /** @var list<array{id: string, left: int}> $freezes */
        $freezes = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT id, days_left FROM shop_freeze WHERE user_id = ? AND days_left > 0 ORDER BY days_left, acquired_at, id FOR UPDATE',
            [$userId->toBinary()],
            [ParameterType::BINARY],
        ) as $row) {
            if (\is_string($row['id']) && is_numeric($row['days_left'])) {
                $freezes[] = ['id' => $row['id'], 'left' => (int) $row['days_left']];
            }
        }
        $uses = self::plan(array_column($freezes, 'left'), $days);
        if (null === $uses) {
            return false;
        }
        foreach ($uses as $i => $used) {
            $this->connection->executeStatement(
                'UPDATE shop_freeze SET days_left = days_left - ? WHERE id = ?',
                [$used, $freezes[$i]['id']],
                [ParameterType::INTEGER, ParameterType::BINARY],
            );
        }

        return true;
    }

    /**
     * Which freezes cover $days, and how many days each gives.
     *
     * @param list<int> $left the freezes' days left, shortest first
     *
     * @return array<int, int>|null days used by freeze index; null when they do not cover $days
     */
    public static function plan(array $left, int $days): ?array
    {
        if (array_sum($left) < $days) {
            return null;
        }
        // The smallest freeze long enough on its own.
        foreach ($left as $i => $daysLeft) {
            if ($daysLeft >= $days) {
                return [$i => $days];
            }
        }
        // Else the shortest ones first.
        $uses = [];
        $missing = $days;
        foreach ($left as $i => $daysLeft) {
            $used = min($daysLeft, $missing);
            $uses[$i] = $used;
            $missing -= $used;
            if (0 === $missing) {
                break;
            }
        }

        return $uses;
    }
}
