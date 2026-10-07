<?php

declare(strict_types=1);

namespace App\Gamification\Streak;

use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Queues the "streak in danger" reminders now due (docs/NOTIFICATIONS.md, § 5): each one claimed
 * once, then sent by the async queue; one missed by more than CATCH_UP_MINUTES is dropped. Run
 * every minute with the session reminders (`app:training:send-reminders`, or the hosting tick).
 */
final readonly class StreakReminderDispatcher
{
    public function __construct(
        private StreakReminders $reminders,
        private MessageBusInterface $bus,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int the reminders queued
     */
    public function dispatchDue(): int
    {
        $now = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'));
        $late = $now->modify(\sprintf('-%d minutes', StreakReminders::CATCH_UP_MINUTES));
        $sent = 0;
        foreach ($this->reminders->due($now) as ['userId' => $userId, 'dueAt' => $dueAt]) {
            if (!$this->reminders->claim($userId, $dueAt) || $dueAt < $late) {
                continue;
            }
            $this->bus->dispatch(new StreakReminderDue(Uuid::fromBinary($userId)->toRfc4122(), $dueAt->format(\DATE_ATOM)));
            ++$sent;
        }

        return $sent;
    }
}
