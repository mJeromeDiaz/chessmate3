<?php

declare(strict_types=1);

namespace App\Shop\Reward;

use App\Enum\Shop\LedgerKind;
use App\Gamification\Streak\StreakRewardInterface;
use App\Shop\Wallet\Wallets;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The daily chest (docs/SHOP.md, § 4 ter): 6 to 12 crystals drawn at random with the streak's
 * announcement, plus 10 per day of a streak badge won that day (1,000 at most), one chest. Credited
 * when written, outside the sessions' daily cap; the chest only reveals them (shown once). Plain
 * SQL inside the exercise's transaction; the unique index makes it once a day.
 *
 * @phpstan-type DailyView array{id: string, localDate: string, streak: int, badgeDays: int|null, dailyCrowns: int, badgeCrowns: int, crowns: int, opened: bool}
 */
final readonly class DailyRewarder implements StreakRewardInterface
{
    public const DAILY_MIN = 6;
    public const DAILY_MAX = 12;
    public const CROWNS_PER_BADGE_DAY = 10;
    public const BADGE_MAX = 1_000;

    private const COLUMNS = 'id, local_date, streak, badge_days, daily_crowns, badge_crowns, crowns, opened_at';

    public function __construct(
        private Connection $connection,
        private Wallets $wallets,
    ) {
    }

    /** A streak badge's bonus: 10 per day, 1,000 at most (100 days and beyond). */
    public static function badgeCrowns(int $days): int
    {
        return min(max(0, $days) * self::CROWNS_PER_BADGE_DAY, self::BADGE_MAX);
    }

    public function grant(Uuid $userId, string $localDate, int $streak, ?int $badgeDays, \DateTimeImmutable $now): void
    {
        $daily = random_int(self::DAILY_MIN, self::DAILY_MAX);
        $badge = null === $badgeDays ? 0 : self::badgeCrowns($badgeDays);
        $balance = $this->wallets->lock($userId, $now);
        $inserted = $this->connection->executeStatement(
            'INSERT IGNORE INTO shop_daily_reward (id, user_id, local_date, streak, badge_days, daily_crowns, badge_crowns, crowns, created_at, opened_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NULL)',
            [Uuid::v7()->toBinary(), $userId->toBinary(), $localDate, $streak, $badgeDays, $daily, $badge, $daily + $badge, self::utc($now)],
            [
                ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER,
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING,
            ],
        );
        // Already rewarded that day.
        if (0 === $inserted) {
            return;
        }
        $reason = null === $badgeDays ? 'Coffre du jour' : \sprintf('Coffre de série · %d jours', $badgeDays);
        $this->wallets->move($userId, $balance, $daily + $badge, LedgerKind::Reward, ['reason' => $reason], $now);
    }

    /**
     * The player's daily chests not seen yet, oldest first: shown after the streak's celebration,
     * or later on the dashboard (none is missed).
     *
     * @return list<DailyView>
     */
    public function unopened(Uuid $userId, int $limit = 20): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS.' FROM shop_daily_reward WHERE user_id = ? AND opened_at IS NULL ORDER BY local_date, id LIMIT ?',
            [$userId->toBinary(), $limit],
            [ParameterType::BINARY, ParameterType::INTEGER],
        );

        return array_map(self::view(...), $rows);
    }

    /**
     * Opens a daily chest of the player (idempotent).
     *
     * @return array{reward: DailyView, balance: int}|null null when it is not the player's
     */
    public function open(Uuid $userId, Uuid $rewardId, \DateTimeImmutable $now): ?array
    {
        $this->connection->executeStatement(
            'UPDATE shop_daily_reward SET opened_at = ? WHERE id = ? AND user_id = ? AND opened_at IS NULL',
            [self::utc($now), $rewardId->toBinary(), $userId->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY],
        );
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM shop_daily_reward WHERE id = ? AND user_id = ?',
            [$rewardId->toBinary(), $userId->toBinary()],
            [ParameterType::BINARY, ParameterType::BINARY],
        );

        return false === $row ? null : ['reward' => self::view($row), 'balance' => $this->wallets->balance($userId)];
    }

    /** The player's crowns now. */
    public function balance(Uuid $userId): int
    {
        return $this->wallets->balance($userId);
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return DailyView
     */
    private static function view(array $row): array
    {
        return [
            'id' => Uuid::fromBinary(self::string($row['id']))->toRfc4122(),
            'localDate' => self::string($row['local_date']),
            'streak' => self::int($row['streak']),
            'badgeDays' => null === $row['badge_days'] ? null : self::int($row['badge_days']),
            'dailyCrowns' => self::int($row['daily_crowns']),
            'badgeCrowns' => self::int($row['badge_crowns']),
            'crowns' => self::int($row['crowns']),
            'opened' => null !== $row['opened_at'],
        ];
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function string(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
