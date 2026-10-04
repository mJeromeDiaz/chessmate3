<?php

declare(strict_types=1);

namespace App\Training\Plan;

use App\Enum\Training\Repetition;

/**
 * When a saved session takes place: a local time on some days of the week, in the user's
 * timezone (docs/TRAINING.md). On demand, it has no occurrence. Pure: no clock, no storage.
 */
final readonly class Schedule
{
    /**
     * @param string    $time     local time "HH:MM" (ignored on demand)
     * @param list<int> $weekdays ISO days of the week, 1 = Monday ... 7 = Sunday
     */
    public function __construct(
        public Repetition $repetition,
        public string $time,
        public array $weekdays,
        public \DateTimeZone $timezone,
    ) {
    }

    /**
     * The first occurrence strictly after $after, as a UTC instant; null on demand. A local time
     * skipped by a daylight saving change happens at the first instant after the gap (PHP's rule).
     */
    public function nextAfter(\DateTimeImmutable $after): ?\DateTimeImmutable
    {
        if (Repetition::OnDemand === $this->repetition || [] === $this->weekdays) {
            return null;
        }
        [$hour, $minute] = array_map('intval', explode(':', $this->time));
        $local = $after->setTimezone($this->timezone);
        for ($i = 0; $i <= 7; ++$i) {
            $day = $local->modify(sprintf('+%d days', $i));
            if (!\in_array((int) $day->format('N'), $this->weekdays, true)) {
                continue;
            }
            $at = $day->setTime($hour, $minute);
            if ($at > $after) {
                return $at->setTimezone(new \DateTimeZone('UTC'));
            }
        }

        return null;
    }
}
