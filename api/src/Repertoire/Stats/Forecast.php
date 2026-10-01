<?php

declare(strict_types=1);

namespace App\Repertoire\Stats;

/**
 * Cards falling due by local day (the user's time zone), today first: overdue cards count today.
 */
final class Forecast
{
    public const DAYS = 7;

    /**
     * The instant the forecast stops at: the start of the local day after the last one.
     */
    public static function until(\DateTimeImmutable $now, \DateTimeZone $zone): \DateTimeImmutable
    {
        return $now->setTimezone($zone)->setTime(0, 0)->modify(sprintf('+%d days', self::DAYS));
    }

    /**
     * @param list<\DateTimeImmutable> $dues
     *
     * @return list<array{date: string, due: int}>
     */
    public static function days(array $dues, \DateTimeImmutable $now, \DateTimeZone $zone): array
    {
        $today = $now->setTimezone($zone)->setTime(0, 0);
        $days = [];
        for ($i = 0; $i < self::DAYS; ++$i) {
            $days[$today->modify(sprintf('+%d days', $i))->format('Y-m-d')] = 0;
        }
        $first = array_key_first($days);
        foreach ($dues as $due) {
            $date = $due->setTimezone($zone)->format('Y-m-d');
            if ($date < $first) {
                $date = $first;
            }
            if (isset($days[$date])) {
                ++$days[$date];
            }
        }

        return array_map(static fn (string $date, int $count): array => ['date' => $date, 'due' => $count], array_map('strval', array_keys($days)), array_values($days));
    }
}
