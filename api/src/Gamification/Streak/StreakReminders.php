<?php

declare(strict_types=1);

namespace App\Gamification\Streak;

use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\Uid\Uuid;

/**
 * The "streak in danger" reminder (docs/NOTIFICATIONS.md, § 5): its settings, and when it is next
 * due. The first exercise of a local day schedules it for the next day at the chosen hour; it is
 * claimed once by the minute's dispatch (the due time cleared), so an exercise played before that
 * hour simply moves it to the day after.
 *
 * @phpstan-type Settings array{enabled: bool, hour: int, email: bool}
 */
final readonly class StreakReminders
{
    public const DEFAULT_HOUR = 20;
    public const MIN_HOUR = 18;
    public const MAX_HOUR = 23;
    /** A reminder the cron missed is still sent this late, never on the next day. */
    public const CATCH_UP_MINUTES = 30;

    public function __construct(
        private Connection $connection,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return Settings
     */
    public function settings(User $user): array
    {
        $row = $this->connection->fetchAssociative(
            'SELECT enabled, hour, email FROM gamification_streak_reminder WHERE user_id = ?',
            [$user->getId()->toBinary()],
            [ParameterType::BINARY],
        );
        if (false === $row) {
            return ['enabled' => true, 'hour' => self::DEFAULT_HOUR, 'email' => false];
        }

        return [
            'enabled' => (bool) $row['enabled'],
            'hour' => is_numeric($row['hour']) ? (int) $row['hour'] : self::DEFAULT_HOUR,
            'email' => (bool) $row['email'],
        ];
    }

    /**
     * Replaces the settings; the due time follows them (the day after the last active day, if
     * still ahead).
     *
     * @param Settings $settings
     */
    public function update(User $user, array $settings): void
    {
        $lastDay = $this->connection->fetchOne('SELECT local_date FROM gamification_streak_notice WHERE user_id = ?', [$user->getId()->toBinary()], [ParameterType::BINARY]);
        $dueAt = \is_string($lastDay) ? $this->dueAfter($user, $lastDay, $settings) : null;
        $this->write($user, $settings, null !== $dueAt && $dueAt > $this->clock->now() ? $dueAt : null);
    }

    /**
     * Schedules the reminder of the day after $activeDay (the first exercise of that local day).
     */
    public function schedule(User $user, string $activeDay): void
    {
        $settings = $this->settings($user);
        $this->write($user, $settings, $this->dueAfter($user, $activeDay, $settings));
    }

    /**
     * The reminders due at $now, oldest first.
     *
     * @return list<array{userId: string, dueAt: \DateTimeImmutable}> userId: binary
     */
    public function due(\DateTimeImmutable $now): array
    {
        $due = [];
        foreach ($this->connection->fetchAllAssociative(
            'SELECT user_id, next_due_at FROM gamification_streak_reminder WHERE next_due_at <= ? AND enabled = 1 ORDER BY next_due_at',
            [$now->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
        ) as $row) {
            if (\is_string($row['user_id']) && \is_string($row['next_due_at'])) {
                $due[] = ['userId' => $row['user_id'], 'dueAt' => new \DateTimeImmutable($row['next_due_at'], new \DateTimeZone('UTC'))];
            }
        }

        return $due;
    }

    /**
     * Clears the due time, unless another dispatch (or an exercise) changed it first: true for the
     * one caller that must send it.
     */
    public function claim(string $userId, \DateTimeImmutable $dueAt): bool
    {
        return 1 === $this->connection->executeStatement(
            'UPDATE gamification_streak_reminder SET next_due_at = NULL WHERE user_id = ? AND next_due_at = ?',
            [$userId, $dueAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s')],
            [ParameterType::BINARY, ParameterType::STRING],
        );
    }

    /**
     * @param Settings $settings
     */
    private function dueAfter(User $user, string $day, array $settings): ?\DateTimeImmutable
    {
        if (!$settings['enabled']) {
            return null;
        }
        $timezone = $user->getDateTimeZone();
        $next = (new \DateTimeImmutable($day.' 00:00:00', new \DateTimeZone('UTC')))->modify('+1 day')->format('Y-m-d');

        return (new \DateTimeImmutable(\sprintf('%s %02d:00:00', $next, $settings['hour']), $timezone))->setTimezone(new \DateTimeZone('UTC'));
    }

    /**
     * @param Settings $settings
     */
    private function write(User $user, array $settings, ?\DateTimeImmutable $dueAt): void
    {
        $this->connection->executeStatement(
            'INSERT INTO gamification_streak_reminder (id, user_id, enabled, hour, email, next_due_at) VALUES (?, ?, ?, ?, ?, ?) AS new
             ON DUPLICATE KEY UPDATE enabled = new.enabled, hour = new.hour, email = new.email, next_due_at = new.next_due_at',
            [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $settings['enabled'], $settings['hour'], $settings['email'], $dueAt?->format('Y-m-d H:i:s')],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::BOOLEAN, ParameterType::INTEGER, ParameterType::BOOLEAN, ParameterType::STRING],
        );
    }
}
