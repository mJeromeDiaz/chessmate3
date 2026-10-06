<?php

declare(strict_types=1);

namespace App\Training\Calendar;

use App\Entity\Training\Plan;
use App\Entity\User;
use App\Enum\Training\Module;
use App\Enum\Training\Repetition;
use Psr\Clock\ClockInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Saved sessions as an iCalendar document (RFC 5545, docs/TRAINING.md, calendar): one recurring
 * event per repeated session (RRULE WEEKLY on its days, at its local time in the user's zone), an
 * alarm when the session has a reminder. On demand sessions have no time and are left out.
 * Written by hand: the libraries at hand don't write RRULE.
 */
final readonly class IcsWriter
{
    private const DAYS = [1 => 'MO', 2 => 'TU', 3 => 'WE', 4 => 'TH', 5 => 'FR', 6 => 'SA', 7 => 'SU'];
    private const LINE_OCTETS = 75;

    public function __construct(
        private ClockInterface $clock,
        #[Autowire('%env(FRONTEND_URL)%')]
        private string $frontendUrl,
    ) {
    }

    /**
     * @param list<Plan> $plans the user's sessions to show
     */
    public function calendar(User $user, array $plans, string $name): string
    {
        $timezone = $user->getDateTimeZone();
        $now = $this->clock->now();
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//DontStayRooky//Sessions//FR',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'X-WR-CALNAME:'.self::text($name),
            'X-WR-TIMEZONE:'.$timezone->getName(),
            // Subscribed clients poll the feed: a hint of how often (most choose by themselves).
            'REFRESH-INTERVAL;VALUE=DURATION:PT1H',
            'X-PUBLISHED-TTL:PT1H',
        ];
        $utc = 'UTC' === $timezone->getName();
        if (!$utc) {
            array_push($lines, ...TimezoneComponent::lines($timezone, (int) $now->setTimezone($timezone)->format('Y')));
        }
        foreach ($plans as $plan) {
            array_push($lines, ...$this->event($plan, $timezone, $utc, $now));
        }
        $lines[] = 'END:VCALENDAR';

        return implode('', array_map(self::fold(...), $lines));
    }

    public static function fileName(Plan $plan): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower((string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $plan->getTitle()))), '-');

        return ('' !== $slug ? $slug : 'session').'.ics';
    }

    /**
     * @return list<string>
     */
    private function event(Plan $plan, \DateTimeZone $timezone, bool $utc, \DateTimeImmutable $now): array
    {
        $first = Repetition::OnDemand === $plan->getRepetition() ? null : $plan->schedule()->nextAfter($plan->getUpdatedAt());
        if (null === $first) {
            return [];
        }
        $title = '' !== $plan->getTitle() ? $plan->getTitle() : 'Session Don\'t Stay Rooky';
        $minutes = max(1, array_sum(array_column($plan->getSteps(), 'minutes')));
        $days = $plan->getWeekdays();
        sort($days);
        $url = $this->frontendUrl.'/#/session/plans/'.$plan->getId()->toRfc4122();
        $program = implode(', ', array_map(
            static fn (array $step): string => sprintf('%s %d min', Module::tryFrom($step['module'])?->label() ?? $step['module'], $step['minutes']),
            $plan->getSteps(),
        ));
        $description = implode("\n", array_filter([$plan->getDescription(), 'Programme : '.$program, $url], static fn (string $part): bool => '' !== $part));

        $lines = [
            'BEGIN:VEVENT',
            'UID:'.$plan->getId()->toRfc4122().'@dontstayrooky',
            'DTSTAMP:'.self::utc($now),
            'LAST-MODIFIED:'.self::utc($plan->getUpdatedAt()),
            $utc ? 'DTSTART:'.self::utc($first) : 'DTSTART;TZID='.$timezone->getName().':'.$first->setTimezone($timezone)->format('Ymd\THis'),
            'DURATION:PT'.$minutes.'M',
            'RRULE:FREQ=WEEKLY;BYDAY='.implode(',', array_map(static fn (int $day): string => self::DAYS[$day], $days)),
            'SUMMARY:'.self::text($title),
            'DESCRIPTION:'.self::text($description),
            'URL:'.$url,
            'CLASS:PRIVATE',
            'TRANSP:OPAQUE',
        ];
        if ($plan->isReminderEnabled()) {
            array_push(
                $lines,
                'BEGIN:VALARM',
                'ACTION:DISPLAY',
                'TRIGGER:-PT'.$plan->getReminderMinutes().'M',
                'DESCRIPTION:'.self::text($title),
                'END:VALARM',
            );
        }
        $lines[] = 'END:VEVENT';

        return $lines;
    }

    private static function utc(\DateTimeImmutable $at): string
    {
        return $at->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /**
     * A TEXT value (RFC 5545 § 3.3.11): backslash, semicolon, comma and line breaks escaped.
     */
    private static function text(string $value): string
    {
        $value = str_replace(["\r\n", "\r"], "\n", $value);

        return str_replace(['\\', ';', ',', "\n"], ['\\\\', '\;', '\,', '\n'], $value);
    }

    /**
     * A content line folded at 75 octets (§ 3.1), never inside a UTF-8 character, ended by CRLF.
     */
    private static function fold(string $line): string
    {
        $out = '';
        $length = 0;
        foreach (mb_str_split($line, 1, 'UTF-8') as $char) {
            $octets = \strlen($char);
            // Continuation lines start with a space, which counts.
            if ($length + $octets > self::LINE_OCTETS) {
                $out .= "\r\n ";
                $length = 1;
            }
            $out .= $char;
            $length += $octets;
        }

        return $out."\r\n";
    }
}
