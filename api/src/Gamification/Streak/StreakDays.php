<?php

declare(strict_types=1);

namespace App\Gamification\Streak;

use App\Gamification\Summary\Streak;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The days a player's daily streak is made of (docs/GAMIFICATION.md, docs/SHOP.md): active days
 * (an exercise logged), frozen days (covered by a freeze when the player came back), and, while
 * the player is away, the missed days the freezes in stock would cover (the streak shows as
 * protected, nothing used yet).
 *
 * @phpstan-type Days array{days: list<string>, frozen: list<string>, protected: bool, freezeDays: int}
 */
final readonly class StreakDays
{
    public function __construct(
        private Connection $connection,
        private FreezeStockInterface $freezes,
    ) {
    }

    /**
     * @return list<string> active local days (Y-m-d), oldest first
     */
    public function active(Uuid $userId, ?string $since = null): array
    {
        return array_values(array_filter($this->connection->fetchFirstColumn(
            'SELECT DISTINCT local_date FROM activity_log_entry WHERE user_id = ? AND local_date >= ? ORDER BY local_date',
            [$userId->toBinary(), $since ?? '0000-01-01'],
            [ParameterType::BINARY],
        ), 'is_string'));
    }

    /**
     * @return list<string> frozen local days (Y-m-d), oldest first
     */
    public function frozen(Uuid $userId): array
    {
        return array_values(array_filter($this->connection->fetchFirstColumn(
            'SELECT local_date FROM gamification_frozen_day WHERE user_id = ? ORDER BY local_date',
            [$userId->toBinary()],
            [ParameterType::BINARY],
        ), 'is_string'));
    }

    /**
     * The streak's days as of $today: the missed days the stock covers count as frozen.
     *
     * @return Days
     */
    public function asOf(Uuid $userId, string $today): array
    {
        $days = $this->active($userId);
        $frozen = $this->frozen($userId);
        $stock = $this->freezes->available($userId);
        $gap = Streak::gap($days, $frozen, $today);
        $protected = [] !== $gap && \count($gap) <= $stock;

        return [
            'days' => $days,
            'frozen' => $protected ? [...$frozen, ...$gap] : $frozen,
            'protected' => $protected,
            'freezeDays' => $stock,
        ];
    }

    /**
     * When the player comes back on $today: the missed days since the streak's last day, covered
     * by freezes when they can (in the caller's transaction).
     *
     * @return list<string> the days frozen now (none when nothing was missed or the stock fell short)
     */
    public function freezeGap(Uuid $userId, string $today, \DateTimeImmutable $now): array
    {
        $gap = Streak::gap($this->active($userId), $this->frozen($userId), $today);
        if ([] === $gap || !$this->freezes->consume($userId, \count($gap), $now)) {
            return [];
        }
        foreach ($gap as $day) {
            $this->connection->executeStatement(
                'INSERT IGNORE INTO gamification_frozen_day (user_id, local_date, created_at) VALUES (?, ?, ?)',
                [$userId->toBinary(), $day, $now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
                [ParameterType::BINARY],
            );
        }

        return $gap;
    }
}
