<?php

declare(strict_types=1);

namespace App\Tests\Unit\Training;

use App\Entity\Training\Plan;
use App\Entity\User;
use App\Enum\Training\Module;
use App\Enum\Training\Repetition;
use App\Training\Calendar\IcsWriter;
use App\Training\Calendar\TimezoneComponent;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Saved sessions as iCalendar (docs/TRAINING.md, calendar): recurring events at the local time,
 * alarms, escaping and folding, VTIMEZONE rules.
 */
final class IcsWriterTest extends TestCase
{
    private const NOW = '2026-09-28 10:00:00';

    public function testARepeatedSessionIsAWeeklyEventAtItsLocalTime(): void
    {
        $user = self::user('Europe/Paris');
        $plan = self::plan($user, Repetition::Daily, '18:30', [5, 1, 2], reminder: true);

        $ics = $this->writer()->calendar($user, [$plan], "Don't Stay Rooky");
        $lines = self::unfold($ics);

        self::assertStringStartsWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n", $ics);
        self::assertStringEndsWith("END:VCALENDAR\r\n", $ics);
        self::assertContains('UID:'.$plan->getId()->toRfc4122().'@dontstayrooky', $lines);
        // Saved on Monday 12:00 in Paris: the first occurrence is that evening.
        self::assertContains('DTSTART;TZID=Europe/Paris:20260928T183000', $lines);
        self::assertContains('DURATION:PT30M', $lines);
        self::assertContains('RRULE:FREQ=WEEKLY;BYDAY=MO,TU,FR', $lines);
        self::assertContains('SUMMARY:Soir\, échecs \; tactique', $lines);
        self::assertContains('DESCRIPTION:Ligne 1\nLigne 2 \\\\ fin\nProgramme : Libre 10 min\, Puzzles 20 min\nhttps://app.example/#/session/plans/'.$plan->getId()->toRfc4122(), $lines);
        self::assertContains('TRIGGER:-PT60M', $lines);
        self::assertContains('TZID:Europe/Paris', $lines);
    }

    public function testNoReminderNoAlarmAndOnDemandSessionsAreLeftOut(): void
    {
        $user = self::user('Europe/Paris');
        $weekly = self::plan($user, Repetition::Weekly, '07:00', [3]);
        $onDemand = self::plan($user, Repetition::OnDemand, null, []);

        $ics = $this->writer()->calendar($user, [$weekly, $onDemand], "Don't Stay Rooky");

        self::assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
        self::assertStringNotContainsString('VALARM', $ics);
        self::assertContains('DTSTART;TZID=Europe/Paris:20260930T070000', self::unfold($ics));
    }

    public function testAUtcUserGetsUtcTimesAndNoTimezoneComponent(): void
    {
        $user = self::user('UTC');
        $ics = $this->writer()->calendar($user, [self::plan($user, Repetition::Weekly, '07:00', [3])], "Don't Stay Rooky");

        self::assertStringNotContainsString('VTIMEZONE', $ics);
        self::assertContains('DTSTART:20260930T070000Z', self::unfold($ics));
    }

    public function testLongLinesAreFoldedAt75OctetsWithoutSplittingACharacter(): void
    {
        $user = self::user('UTC');
        $plan = self::plan($user, Repetition::Weekly, '07:00', [3], title: str_repeat('é', 100));

        $ics = $this->writer()->calendar($user, [$plan], "Don't Stay Rooky");

        foreach (explode("\r\n", $ics) as $line) {
            self::assertLessThanOrEqual(75, \strlen($line));
            self::assertTrue(mb_check_encoding($line, 'UTF-8'), $line);
        }
        self::assertContains('SUMMARY:'.str_repeat('é', 100), self::unfold($ics));
    }

    public function testTimezoneRulesFollowTheZone(): void
    {
        self::assertSame([
            'BEGIN:VTIMEZONE', 'TZID:Europe/Paris',
            'BEGIN:DAYLIGHT', 'DTSTART:19700329T020000', 'RRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=-1SU', 'TZOFFSETFROM:+0100', 'TZOFFSETTO:+0200', 'TZNAME:CEST', 'END:DAYLIGHT',
            'BEGIN:STANDARD', 'DTSTART:19701025T030000', 'RRULE:FREQ=YEARLY;BYMONTH=10;BYDAY=-1SU', 'TZOFFSETFROM:+0200', 'TZOFFSETTO:+0100', 'TZNAME:CET', 'END:STANDARD',
            'END:VTIMEZONE',
        ], TimezoneComponent::lines(new \DateTimeZone('Europe/Paris'), 2026));

        $newYork = implode("\n", TimezoneComponent::lines(new \DateTimeZone('America/New_York'), 2026));
        self::assertStringContainsString("DTSTART:19700308T020000\nRRULE:FREQ=YEARLY;BYMONTH=3;BYDAY=2SU\nTZOFFSETFROM:-0500\nTZOFFSETTO:-0400", $newYork);
        self::assertStringContainsString("DTSTART:19701101T020000\nRRULE:FREQ=YEARLY;BYMONTH=11;BYDAY=1SU\nTZOFFSETFROM:-0400\nTZOFFSETTO:-0500", $newYork);

        self::assertSame(
            ['BEGIN:VTIMEZONE', 'TZID:Asia/Kolkata', 'BEGIN:STANDARD', 'DTSTART:19700101T000000', 'TZOFFSETFROM:+0530', 'TZOFFSETTO:+0530', 'TZNAME:IST', 'END:STANDARD', 'END:VTIMEZONE'],
            TimezoneComponent::lines(new \DateTimeZone('Asia/Kolkata'), 2026),
        );
    }

    public function testTheFileNameComesFromTheTitle(): void
    {
        $user = self::user('UTC');

        self::assertSame('soir-echecs-tactique.ics', IcsWriter::fileName(self::plan($user, Repetition::Weekly, '07:00', [3])));
        self::assertSame('session.ics', IcsWriter::fileName(self::plan($user, Repetition::Weekly, '07:00', [3], title: '!!')));
    }

    private function writer(): IcsWriter
    {
        return new IcsWriter(new MockClock(self::NOW, 'UTC'), 'https://app.example');
    }

    private static function user(string $timezone): User
    {
        $user = new User();
        $user->setTimezone($timezone);

        return $user;
    }

    /**
     * @param list<int> $weekdays
     */
    private static function plan(User $user, Repetition $repetition, ?string $time, array $weekdays, bool $reminder = false, string $title = 'Soir, échecs ; tactique'): Plan
    {
        $now = new \DateTimeImmutable(self::NOW, new \DateTimeZone('UTC'));
        $plan = new Plan($user, $now);
        $plan->update(
            title: $title,
            description: "Ligne 1\r\nLigne 2 \\ fin",
            steps: [
                ['module' => Module::Free, 'minutes' => 10, 'notes' => '', 'settings' => []],
                ['module' => Module::Puzzles, 'minutes' => 20, 'notes' => '', 'settings' => []],
            ],
            repetition: $repetition,
            time: $time,
            weekdays: $weekdays,
            public: false,
            reminderEnabled: $reminder,
            reminderChannels: $reminder ? ['email'] : [],
            reminderMinutes: 60,
            calendarEnabled: true,
            now: $now,
        );

        return $plan;
    }

    /**
     * @return list<string>
     */
    private static function unfold(string $ics): array
    {
        return explode("\r\n", rtrim(str_replace("\r\n ", '', $ics), "\r\n"));
    }
}
