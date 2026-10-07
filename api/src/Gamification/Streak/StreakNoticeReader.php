<?php

declare(strict_types=1);

namespace App\Gamification\Streak;

use App\Activity\Log\LocalDate;
use App\Entity\User;
use App\Gamification\Summary\Streak;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;

/**
 * Today's streak announcement not shown yet (docs/GAMIFICATION.md, "Annonce de la série"), and
 * its acknowledgement once the front showed it. An announcement of a past day is never shown.
 *
 * @phpstan-type Notice array{streak: int, previousStreak: int, badge: string|null, week: list<bool>, nextMilestone: int|null, localDate: string}
 */
final readonly class StreakNoticeReader
{
    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Notice|null
     */
    public function pending(User $user): ?array
    {
        $id = $user->getId()->toBinary();
        $today = LocalDate::of($this->clock->now(), $user->getDateTimeZone())->format('Y-m-d');
        $row = $this->connection->fetchAssociative(
            'SELECT streak, previous_streak, badge FROM gamification_streak_notice WHERE user_id = ? AND local_date = ? AND acknowledged_at IS NULL',
            [$id, $today],
            [ParameterType::BINARY],
        );
        if (false === $row || !is_numeric($row['streak']) || !is_numeric($row['previous_streak'])) {
            return null;
        }
        $days = array_values(array_filter($this->connection->fetchFirstColumn(
            'SELECT DISTINCT local_date FROM activity_log_entry WHERE user_id = ? AND local_date >= ?',
            [$id, (new \DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d')],
            [ParameterType::BINARY],
        ), 'is_string'));
        // Today's exercise may still be in the outbox.
        $days[] = $today;

        return [
            'streak' => (int) $row['streak'],
            'previousStreak' => (int) $row['previous_streak'],
            'badge' => \is_string($row['badge']) ? $row['badge'] : null,
            'week' => Streak::week($days, $today),
            'nextMilestone' => Streak::nextMilestone((int) $row['streak']),
            'localDate' => $today,
        ];
    }

    /**
     * Marks today's announcement as shown (idempotent).
     */
    public function acknowledge(User $user): void
    {
        $this->connection->executeStatement(
            'UPDATE gamification_streak_notice SET acknowledged_at = ? WHERE user_id = ? AND acknowledged_at IS NULL',
            [$this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $user->getId()->toBinary()],
            [ParameterType::STRING, ParameterType::BINARY],
        );
    }
}
