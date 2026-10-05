<?php

declare(strict_types=1);

namespace App\Training\Calendar;

/**
 * The VTIMEZONE of an IANA zone (RFC 5545 § 3.6.5), derived from PHP's transitions: one STANDARD
 * (and one DAYLIGHT) observance repeated yearly on the weekday rule of the given year's changes
 * ("last Sunday of March" ...). Clients that know the TZID (most) ignore it; others need it.
 * A rule that isn't "n-th or last weekday of a month" is approximated by the nearest such rule.
 */
final class TimezoneComponent
{
    private const DAYS = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];

    /**
     * @return list<string> unfolded content lines
     */
    public static function lines(\DateTimeZone $timezone, int $year): array
    {
        $utc = new \DateTimeZone('UTC');
        $begin = (new \DateTimeImmutable(sprintf('%d-01-01', $year), $utc))->getTimestamp();
        $end = (new \DateTimeImmutable(sprintf('%d-01-01', $year + 1), $utc))->getTimestamp();
        $transitions = $timezone->getTransitions($begin, $end);
        if ([] === $transitions) {
            $transitions = [['ts' => $begin, 'offset' => $timezone->getOffset(new \DateTimeImmutable('@'.$begin)), 'isdst' => false, 'abbr' => '']];
        }

        $lines = ['BEGIN:VTIMEZONE', 'TZID:'.$timezone->getName()];
        $changes = \array_slice($transitions, 1);
        if ([] === $changes) {
            // No change this year: one fixed offset.
            $offset = self::offset($transitions[0]['offset']);
            array_push($lines, 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:'.$offset, 'TZOFFSETTO:'.$offset);
            if ('' !== $transitions[0]['abbr']) {
                $lines[] = 'TZNAME:'.$transitions[0]['abbr'];
            }
            $lines[] = 'END:STANDARD';
        }
        $previous = $transitions[0]['offset'];
        foreach ($changes as $change) {
            // The onset in the local time in force before it.
            $local = (new \DateTimeImmutable('@'.($change['ts'] + $previous)))->setTimezone($utc);
            $month = (int) $local->format('n');
            $day = (int) $local->format('j');
            $weekday = (int) $local->format('N');
            $ordinal = $day + 7 > (int) $local->format('t') ? -1 : intdiv($day - 1, 7) + 1;
            $kind = $change['isdst'] ? 'DAYLIGHT' : 'STANDARD';

            array_push(
                $lines,
                'BEGIN:'.$kind,
                'DTSTART:'.self::dayIn1970($month, $weekday, $ordinal).'T'.$local->format('His'),
                sprintf('RRULE:FREQ=YEARLY;BYMONTH=%d;BYDAY=%d%s', $month, $ordinal, self::DAYS[$weekday]),
                'TZOFFSETFROM:'.self::offset($previous),
                'TZOFFSETTO:'.self::offset($change['offset']),
            );
            if ('' !== $change['abbr']) {
                $lines[] = 'TZNAME:'.$change['abbr'];
            }
            $lines[] = 'END:'.$kind;
            $previous = $change['offset'];
        }
        $lines[] = 'END:VTIMEZONE';

        return $lines;
    }

    /**
     * "+0200", "-0430".
     */
    private static function offset(int $seconds): string
    {
        $abs = abs($seconds);

        return sprintf('%s%02d%02d', $seconds < 0 ? '-' : '+', intdiv($abs, 3600), intdiv($abs % 3600, 60));
    }

    /**
     * The n-th (or last, -1) given weekday of a month of 1970, as "YYYYMMDD": the observance's
     * first onset, so that it covers every event.
     */
    private static function dayIn1970(int $month, int $weekday, int $ordinal): string
    {
        $utc = new \DateTimeZone('UTC');
        if (-1 === $ordinal) {
            $date = (new \DateTimeImmutable(sprintf('1970-%02d-01', $month), $utc))->modify('last day of this month');
            while ((int) $date->format('N') !== $weekday) {
                $date = $date->modify('-1 day');
            }
        } else {
            $date = new \DateTimeImmutable(sprintf('1970-%02d-01', $month), $utc);
            while ((int) $date->format('N') !== $weekday) {
                $date = $date->modify('+1 day');
            }
            $date = $date->modify(sprintf('+%d weeks', $ordinal - 1));
        }

        return $date->format('Ymd');
    }
}
