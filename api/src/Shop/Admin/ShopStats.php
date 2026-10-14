<?php

declare(strict_types=1);

namespace App\Shop\Admin;

use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;

/**
 * The store's figures for the admin (docs/SHOP.md), over the last $days days (UTC): crowns given,
 * taken back, earned by sessions (§ 4 bis) and spent, sales by offer, crowns spent and earned by
 * day, what players hold now.
 *
 * @phpstan-type Stats array{days: int, totals: array{circulating: int, holders: int, granted: int, revoked: int, earned: int, rewarded: int, spent: int, purchases: int, buyers: int}, offers: list<array{offerName: string, purchases: int, quantity: int, crowns: int}>, daily: list<array{date: string, crowns: int, earned: int}>, held: array<string, int>, freezeDays: int}
 */
final readonly class ShopStats
{
    public const PERIODS = [7, 30, 90];

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Stats
     */
    public function of(int $days): array
    {
        $today = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $from = $today->modify(\sprintf('-%d days', $days - 1));
        $since = $from->format('Y-m-d H:i:s');

        $sums = $this->connection->fetchAssociative(
            "SELECT
                COALESCE(SUM(CASE WHEN kind = 'grant' THEN delta END), 0) AS granted,
                COALESCE(-SUM(CASE WHEN kind = 'revoke' THEN delta END), 0) AS revoked,
                COALESCE(SUM(CASE WHEN kind = 'reward' THEN delta END), 0) AS earned,
                COUNT(CASE WHEN kind = 'reward' THEN 1 END) AS rewarded,
                COALESCE(-SUM(CASE WHEN kind = 'purchase' THEN delta END), 0) AS spent,
                COUNT(CASE WHEN kind = 'purchase' THEN 1 END) AS purchases,
                COUNT(DISTINCT CASE WHEN kind = 'purchase' THEN user_id END) AS buyers
               FROM shop_ledger_entry WHERE created_at >= ?",
            [$since],
        ) ?: [];
        $wallets = $this->connection->fetchAssociative('SELECT COALESCE(SUM(balance), 0) AS circulating, COUNT(CASE WHEN balance > 0 THEN 1 END) AS holders FROM shop_wallet') ?: [];

        $offers = array_map(static fn (array $row): array => [
            'offerName' => \is_string($row['offer_name']) ? $row['offer_name'] : '?',
            'purchases' => self::int($row['purchases']),
            'quantity' => self::int($row['quantity']),
            'crowns' => self::int($row['crowns']),
        ], $this->connection->fetchAllAssociative(
            "SELECT offer_name, COUNT(*) AS purchases, COALESCE(SUM(quantity), 0) AS quantity, -SUM(delta) AS crowns
               FROM shop_ledger_entry WHERE kind = 'purchase' AND created_at >= ?
              GROUP BY offer_name ORDER BY crowns DESC, offer_name",
            [$since],
        ));

        $spentByDay = $earnedByDay = [];
        foreach ($this->connection->fetchAllAssociative(
            "SELECT DATE(created_at) AS day,
                    COALESCE(-SUM(CASE WHEN kind = 'purchase' THEN delta END), 0) AS crowns,
                    COALESCE(SUM(CASE WHEN kind = 'reward' THEN delta END), 0) AS earned
               FROM shop_ledger_entry
              WHERE kind IN ('purchase', 'reward') AND created_at >= ? GROUP BY DATE(created_at)",
            [$since],
        ) as $row) {
            if (\is_string($row['day'])) {
                $spentByDay[$row['day']] = self::int($row['crowns']);
                $earnedByDay[$row['day']] = self::int($row['earned']);
            }
        }
        $daily = [];
        for ($day = $from; $day <= $today; $day = $day->modify('+1 day')) {
            $key = $day->format('Y-m-d');
            $daily[] = ['date' => $key, 'crowns' => $spentByDay[$key] ?? 0, 'earned' => $earnedByDay[$key] ?? 0];
        }

        $held = [];
        foreach ($this->connection->fetchAllAssociative('SELECT item, SUM(quantity) AS quantity FROM shop_inventory_item GROUP BY item HAVING SUM(quantity) > 0') as $row) {
            if (\is_string($row['item'])) {
                $held[$row['item']] = self::int($row['quantity']);
            }
        }
        foreach ($this->connection->fetchAllAssociative('SELECT item, COUNT(*) AS n FROM shop_freeze WHERE days_left > 0 GROUP BY item') as $row) {
            if (\is_string($row['item'])) {
                $held[$row['item']] = self::int($row['n']);
            }
        }
        ksort($held);

        return [
            'days' => $days,
            'totals' => [
                'circulating' => self::int($wallets['circulating'] ?? 0),
                'holders' => self::int($wallets['holders'] ?? 0),
                'granted' => self::int($sums['granted'] ?? 0),
                'revoked' => self::int($sums['revoked'] ?? 0),
                'earned' => self::int($sums['earned'] ?? 0),
                'rewarded' => self::int($sums['rewarded'] ?? 0),
                'spent' => self::int($sums['spent'] ?? 0),
                'purchases' => self::int($sums['purchases'] ?? 0),
                'buyers' => self::int($sums['buyers'] ?? 0),
            ],
            'offers' => $offers,
            'daily' => $daily,
            'held' => $held,
            'freezeDays' => self::int($this->connection->fetchOne('SELECT COALESCE(SUM(days_left), 0) FROM shop_freeze')),
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
