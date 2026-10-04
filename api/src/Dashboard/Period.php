<?php

declare(strict_types=1);

namespace App\Dashboard;

use App\Activity\Log\LocalDate;
use App\Entity\User;

/**
 * The last N local days of a user, today included (docs/DASHBOARD.md): `from` and `today` are
 * days in the user's timezone, `since` the UTC instant `from` starts at.
 */
final class Period
{
    public const MIN_DAYS = 7;
    /** 53 weeks: a full year of heatmap columns. */
    public const MAX_DAYS = 371;

    private function __construct(
        public readonly int $days,
        public readonly \DateTimeImmutable $from,
        public readonly \DateTimeImmutable $today,
        public readonly \DateTimeImmutable $since,
    ) {
    }

    /**
     * @param int $days clamped to [MIN_DAYS, MAX_DAYS]
     */
    public static function last(int $days, User $user, \DateTimeImmutable $now): self
    {
        $days = max(self::MIN_DAYS, min(self::MAX_DAYS, $days));
        $timezone = $user->getDateTimeZone();
        $today = LocalDate::of($now, $timezone);
        $from = $today->modify(\sprintf('-%d days', $days - 1));
        $since = (new \DateTimeImmutable($from->format('Y-m-d'), $timezone))->setTimezone(new \DateTimeZone('UTC'));

        return new self($days, $from, $today, $since);
    }

    public function fromDate(): string
    {
        return $this->from->format('Y-m-d');
    }

    public function todayDate(): string
    {
        return $this->today->format('Y-m-d');
    }
}
