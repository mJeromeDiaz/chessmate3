<?php

declare(strict_types=1);

namespace App\Training\Plan\Reminder;

use App\Repository\Training\ReminderLogRepository;
use Psr\Clock\ClockInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Queues the reminders now due (docs/TRAINING.md): each occurrence is claimed once in the reminder
 * log, then sent by the async queue. Run every minute, by cron (`app:training:send-reminders`) or
 * by the hosting tick (docs/DEPLOY_OVH.md).
 */
final readonly class ReminderDispatcher
{
    public function __construct(
        private ReminderScheduler $scheduler,
        private ReminderLogRepository $log,
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
        $sent = 0;
        foreach ($this->scheduler->due($now) as [$plan, $occursAt]) {
            if (!$this->log->claim($plan, $occursAt, $now)) {
                continue;
            }
            $this->bus->dispatch(new SessionReminderDue($plan->getId()->toRfc4122(), $occursAt->format(\DATE_ATOM)));
            ++$sent;
        }

        return $sent;
    }
}
