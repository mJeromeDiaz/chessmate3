<?php

declare(strict_types=1);

namespace App\Training\Plan\Reminder;

use App\Entity\Training\Plan;
use App\Repository\Training\PlanRepository;
use App\Repository\Training\SessionRepository;

/**
 * Which reminders are due now (docs/TRAINING.md, rules validated on 2026-10-04): the reminder of
 * an occurrence is due from `reminderMinutes` before it; one missed by the cron is caught up to
 * {@see self::CATCH_UP_MINUTES} late, never once the session has begun; none when a session was
 * already launched from the plan on the occurrence's local day.
 */
final class ReminderScheduler
{
    public const CATCH_UP_MINUTES = 15;

    public function __construct(
        private readonly PlanRepository $plans,
        private readonly SessionRepository $sessions,
    ) {
    }

    /**
     * @return list<array{Plan, \DateTimeImmutable}> plans and the occurrence (UTC) to remind of
     */
    public function due(\DateTimeImmutable $now): array
    {
        $due = [];
        foreach ($this->plans->findWithReminder() as $plan) {
            $occurrence = self::occurrenceToRemind($plan, $now);
            if (null !== $occurrence && !$this->alreadyLaunched($plan, $occurrence)) {
                $due[] = [$plan, $occurrence];
            }
        }

        return $due;
    }

    /**
     * The occurrence whose reminder time is within the last CATCH_UP_MINUTES (now included), if it
     * has not begun yet.
     */
    public static function occurrenceToRemind(Plan $plan, \DateTimeImmutable $now): ?\DateTimeImmutable
    {
        $delay = $plan->getReminderMinutes();
        // Reminder time R = O - delay, wanted in (now - catch-up, now]: O in (now + delay - catch-up, now + delay].
        $occurrence = $plan->schedule()->nextAfter($now->modify(sprintf('+%d minutes', $delay - self::CATCH_UP_MINUTES)));
        if (null === $occurrence || $occurrence > $now->modify(sprintf('+%d minutes', $delay)) || $occurrence <= $now) {
            return null;
        }

        return $occurrence;
    }

    private function alreadyLaunched(Plan $plan, \DateTimeImmutable $occurrence): bool
    {
        $timezone = $plan->getUser()->getDateTimeZone();
        $utc = new \DateTimeZone('UTC');
        $dayStart = $occurrence->setTimezone($timezone)->setTime(0, 0);

        return $this->sessions->launchedFrom($plan, $dayStart->setTimezone($utc), $dayStart->modify('+1 day')->setTimezone($utc));
    }
}
