<?php

declare(strict_types=1);

namespace App\EarlyAccess\Stats;

/**
 * Small helpers shared by the admin statistics: what MySQL returns, and what it is given.
 */
final class Sql
{
    /** A UTC instant as a DATETIME column holds it. */
    public static function instant(\DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /** COUNT and SUM come back as numeric strings (SUM of no row: null). */
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @param list<int> $values
     */
    public static function median(array $values): ?int
    {
        if ([] === $values) {
            return null;
        }
        sort($values);
        $middle = intdiv(\count($values), 2);

        return 0 === \count($values) % 2 ? intdiv($values[$middle - 1] + $values[$middle], 2) : $values[$middle];
    }

    /**
     * Every day of the period, oldest first, as Y-m-d keys.
     *
     * @return list<string>
     */
    public static function days(\DateTimeImmutable $from, \DateTimeImmutable $today): array
    {
        $days = [];
        for ($day = $from; $day <= $today; $day = $day->modify('+1 day')) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }

    public static function monday(\DateTimeImmutable $day): \DateTimeImmutable
    {
        return $day->setTime(0, 0)->modify(\sprintf('-%d days', (int) $day->format('N') - 1));
    }
}
