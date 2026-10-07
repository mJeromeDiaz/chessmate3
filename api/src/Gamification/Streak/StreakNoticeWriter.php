<?php

declare(strict_types=1);

namespace App\Gamification\Streak;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\Log\LocalDate;
use App\Enum\Gamification\Trophy;
use App\Gamification\Summary\Streak;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Clock\ClockInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Announces the streak (docs/GAMIFICATION.md, "Annonce de la série") when the first exercise of the
 * user's local day enters the outbox, in the transaction of that exercise: the activity log is only
 * written later by the outbox's handler, so the announcement can't wait for it. Only a newer day
 * overwrites the row: a second exercise of the same day, written to the log or not yet, announces
 * nothing more. It also moves the "streak in danger" reminder to the next day.
 */
final readonly class StreakNoticeWriter implements EventSubscriberInterface
{
    public function __construct(
        private Connection $connection,
        private UserRepository $users,
        private ClockInterface $clock,
        private StreakReminders $reminders,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [SendMessageToTransportsEvent::class => 'onSend'];
    }

    public function onSend(SendMessageToTransportsEvent $event): void
    {
        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        // A retry re-sends the event from the worker: it was announced when first sent.
        if (!$message instanceof ExerciseCompleted || [] !== $envelope->all(RedeliveryStamp::class) || !Uuid::isValid($message->userId)) {
            return;
        }
        $user = $this->users->find(Uuid::fromString($message->userId));
        if (null === $user) {
            return;
        }
        $timezone = $user->getDateTimeZone();
        $day = LocalDate::of($message->occurredAt, $timezone)->format('Y-m-d');
        // A past exercise (backfill, a run closed lazily after midnight) announces nothing.
        if ($day !== LocalDate::of($this->clock->now(), $timezone)->format('Y-m-d')) {
            return;
        }
        $id = $user->getId()->toBinary();
        // Already announced today: every later exercise of the day stops at this unique index.
        if ($day === $this->connection->fetchOne('SELECT local_date FROM gamification_streak_notice WHERE user_id = ?', [$id], [ParameterType::BINARY])) {
            return;
        }
        $days =array_values(array_filter($this->connection->fetchFirstColumn(
            'SELECT DISTINCT local_date FROM activity_log_entry WHERE user_id = ? ORDER BY local_date',
            [$id],
            [ParameterType::BINARY],
        ), 'is_string'));
        $streak = Streak::of($days, $day);
        if ($streak['playedToday']) {
            return;
        }
        $length = $streak['current'] + 1;

        // The local date is assigned last: MySQL evaluates the assignments left to right, the
        // conditions before it still see the stored day.
        $this->connection->executeStatement(
            'INSERT INTO gamification_streak_notice (id, user_id, local_date, streak, previous_streak, badge, acknowledged_at)
             VALUES (?, ?, ?, ?, ?, ?, NULL) AS new
             ON DUPLICATE KEY UPDATE
                streak = IF(gamification_streak_notice.local_date < new.local_date, new.streak, gamification_streak_notice.streak),
                previous_streak = IF(gamification_streak_notice.local_date < new.local_date, new.previous_streak, gamification_streak_notice.previous_streak),
                badge = IF(gamification_streak_notice.local_date < new.local_date, new.badge, gamification_streak_notice.badge),
                acknowledged_at = IF(gamification_streak_notice.local_date < new.local_date, NULL, gamification_streak_notice.acknowledged_at),
                local_date = GREATEST(gamification_streak_notice.local_date, new.local_date)',
            [Uuid::v7()->toBinary(), $id, $day, $length, $streak['current'], Trophy::forStreak($length)?->value],
            [ParameterType::BINARY, ParameterType::BINARY, ParameterType::STRING, ParameterType::INTEGER, ParameterType::INTEGER, ParameterType::STRING],
        );
        // Today is safe: the "streak in danger" reminder moves to tomorrow.
        $this->reminders->schedule($user, $day);
    }
}
