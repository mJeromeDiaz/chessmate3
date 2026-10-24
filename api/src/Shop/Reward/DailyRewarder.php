<?php

declare(strict_types=1);

namespace App\Shop\Reward;

use App\Enum\Shop\LedgerKind;
use App\Enum\Shop\WheelStatus;
use App\Gamification\Streak\StreakRewardInterface;
use App\Shop\Wallet\Wallets;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The day's reward (docs/SHOP.md, § 4 ter), with the streak's announcement: the gift wheel to spin
 * that local day ({@see \App\Shop\Wheel\WheelSpinner}; none when the player turned it off), and
 * the chest of a streak badge won that day, 10 crystals per day (1,000 at most). The badge's bonus
 * is credited when written, outside the sessions' daily cap; the chest only reveals it (shown
 * once). Plain SQL inside the exercise's transaction; the unique index makes it once a day. Before
 * the wheel (2026-10-23), the chest also held 6 to 12 crystals drawn at random (`daily_crowns`).
 *
 * @phpstan-type DailyView array{id: string, localDate: string, streak: int, badgeDays: int|null, dailyCrowns: int, badgeCrowns: int, crowns: int, opened: bool, wheel: string|null, wheelOpen: bool}
 */
final readonly class DailyRewarder implements StreakRewardInterface
{
    public const CROWNS_PER_BADGE_DAY = 10;
    public const BADGE_MAX = 1_000;

    /** The day's wheel still to spin: `wheel_open` needs the player's local day as its parameter. */
    private const COLUMNS = "id, local_date, streak, badge_days, daily_crowns, badge_crowns, crowns, opened_at, wheel, (wheel = 'offered' AND local_date = ?) AS wheel_open";

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
        $badge = null === $badgeDays ? 0 : self::badgeCrowns($badgeDays);
        $wheel = false === $this->connection->fetchOne('SELECT daily_wheel FROM app_user WHERE id = ? AND daily_wheel = 1', [$userId->toBinary()], [ParameterType::BINARY])
            ? WheelStatus::Off
            : WheelStatus::Offered;
        $balance = $this->wallets->lock($userId, $now);
        $inserted = $this->connection->executeStatement(
            'INSERT IGNORE INTO shop_daily_reward (id, user_id, local_date, streak, badge_days, daily_crowns, badge_crowns, crowns, wheel, created_at, opened_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?)',
            // Nothing to show: no wheel, no badge.
            [Uuid::v7()->toBinary(), $userId->toBinary(), $localDate, $streak, $badgeDays, $badge, $badge, $wheel->value, self::utc($now), WheelStatus::Off === $wheel && 0 === $badge ? self::utc($now) : null],
            [
                ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER,
                ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING,
            ],
        );
        // Already rewarded that day, or no badge.
        if (0 === $inserted || 0 === $badge) {
            return;
        }
        $this->wallets->move($userId, $balance, $badge, LedgerKind::Reward, ['reason' => \sprintf('Coffre de série · %d jours', $badgeDays)], $now);
    }

    /**
     * What the player has not seen yet, oldest first: the chests (a badge's, or a day's before the
     * wheel), none missed, and today's wheel still to spin (lost at local midnight). Shown after
     * the streak's celebration, or later on the dashboard.
     *
     * @param string $today the player's local day (Y-m-d)
     *
     * @return list<DailyView>
     */
    public function unopened(Uuid $userId, string $today, int $limit = 20): array
    {
        $rows = $this->connection->fetchAllAssociative(
            'SELECT '.self::COLUMNS." FROM shop_daily_reward
              WHERE user_id = ? AND ((opened_at IS NULL AND crowns > 0) OR (wheel = 'offered' AND local_date = ?))
              ORDER BY local_date, id LIMIT ?",
            [$today, $userId->toBinary(), $today, $limit],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER],
        );

        return array_map(self::view(...), $rows);
    }

    /**
     * Today's gift wheel of the player, still to spin: its id, null when there is none (spun,
     * declined, off, or no exercise played today).
     *
     * @param string $today the player's local day (Y-m-d)
     */
    public function todayWheel(Uuid $userId, string $today): ?string
    {
        $id = $this->connection->fetchOne(
            "SELECT id FROM shop_daily_reward WHERE user_id = ? AND local_date = ? AND wheel = 'offered'",
            [$userId->toBinary(), $today],
            [ParameterType::BINARY, ParameterType::STRING],
        );

        return \is_string($id) ? Uuid::fromBinary($id)->toRfc4122() : null;
    }

    /**
     * Opens a daily chest of the player (idempotent).
     *
     * @param string $today the player's local day (Y-m-d)
     *
     * @return array{reward: DailyView, balance: int}|null null when it is not the player's
     */
    public function open(Uuid $userId, Uuid $rewardId, string $today, \DateTimeImmutable $now): ?array
    {
        $this->connection->executeStatement(
            'UPDATE shop_daily_reward SET opened_at = ? WHERE id = ? AND user_id = ? AND opened_at IS NULL',
            [self::utc($now), $rewardId->toBinary(), $userId->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY],
        );
        $reward = $this->find($userId, $rewardId, $today);

        return null === $reward ? null : ['reward' => $reward, 'balance' => $this->wallets->balance($userId)];
    }

    /**
     * A day's reward of the player, null when it is not the player's.
     *
     * @param string $today the player's local day (Y-m-d)
     *
     * @return DailyView|null
     */
    public function find(Uuid $userId, Uuid $rewardId, string $today): ?array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT '.self::COLUMNS.' FROM shop_daily_reward WHERE id = ? AND user_id = ?',
            [$today, $rewardId->toBinary(), $userId->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY, ParameterType::BINARY],
        );

        return false === $row ? null : self::view($row);
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
            'wheel' => \is_string($row['wheel']) ? $row['wheel'] : null,
            'wheelOpen' => 1 === self::int($row['wheel_open']),
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
