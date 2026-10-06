<?php

declare(strict_types=1);

namespace App\Tests\Functional\Training;

use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;

/**
 * The calendar (docs/TRAINING.md, calendar): a private feed address per user, regenerated or
 * revoked at will, listing the saved sessions put in the calendar; one session as an .ics file.
 */
final class CalendarTest extends WoodpeckerWebTestCase
{
    private const FREE = ['module' => 'free', 'minutes' => 10, 'settings' => ['format' => 'book']];

    public function testTheFeedListsTheSessionsPutInTheCalendar(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        self::assertSame(['url' => null, 'webcalUrl' => null], $this->address($alice));

        $evening = $this->save($alice, ['title' => 'Soirs', 'repetition' => 'daily', 'time' => '18:30', 'weekdays' => [1, 2, 3, 4, 5], 'calendarEnabled' => true]);
        $this->save($alice, ['title' => 'Hors calendrier', 'repetition' => 'weekly', 'time' => '09:00', 'weekdays' => [6]]);
        $this->save($alice, ['title' => 'Quand je veux', 'calendarEnabled' => true]);
        $this->save($bob, ['title' => 'Celle de Bob', 'repetition' => 'weekly', 'time' => '09:00', 'weekdays' => [6], 'calendarEnabled' => true]);

        $address = $this->regenerate($alice);
        self::assertNotNull($address['url']);
        self::assertMatchesRegularExpression('#^http://localhost/api/calendar/[A-Za-z0-9_-]{43}\.ics$#', $address['url']);
        self::assertSame(str_replace('http://', 'webcal://', $address['url']), $address['webcalUrl']);
        self::assertSame($address, $this->address($alice), 'The address is shown again.');

        $this->client->request('GET', $address['url']);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/calendar; charset=UTF-8', $response->headers->get('Content-Type'));
        $ics = (string) $response->getContent();
        self::assertSame(1, substr_count($ics, 'BEGIN:VEVENT'));
        self::assertStringContainsString('UID:'.$evening['id'].'@dontstayrooky', $ics);
        self::assertStringContainsString('DTSTART;TZID=Europe/Paris:20260928T183000', $ics);

        // Only a hash and an encrypted copy are stored.
        $token = basename($address['url'], '.ics');
        $stored = self::getContainer()->get(Connection::class)->fetchAssociative('SELECT token_hash, encrypted_token FROM training_calendar_feed');
        self::assertIsArray($stored);
        self::assertSame(hash('sha256', $token), $stored['token_hash']);
        self::assertIsString($stored['encrypted_token']);
        self::assertStringNotContainsString($token, $stored['encrypted_token']);
    }

    public function testASuspendedAccountPublishesNothing(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->save($alice, ['title' => 'Soirs', 'repetition' => 'daily', 'time' => '18:30', 'weekdays' => [1, 2, 3, 4, 5], 'calendarEnabled' => true]);
        $url = $this->regenerate($alice)['url'];
        self::assertIsString($url);
        $path = (string) parse_url($url, \PHP_URL_PATH);
        self::assertSame(200, $this->anonymous('GET', $path));

        $managed = $this->entityManager->find(User::class, $alice->getId());
        self::assertInstanceOf(User::class, $managed);
        $managed->suspend(new \DateTimeImmutable(), null);
        $this->entityManager->flush();

        self::assertSame(404, $this->anonymous('GET', $path));
    }

    public function testRegeneratingOrRevokingClosesThePreviousAddress(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $first = (string) $this->regenerate($alice)['url'];
        $second = (string) $this->regenerate($alice)['url'];
        self::assertNotSame($first, $second);

        self::assertSame(404, $this->anonymous('GET', $first));
        self::assertSame(200, $this->anonymous('GET', $second));

        self::assertSame(204, $this->api('DELETE', '/api/training/calendar', $alice)->getStatusCode());
        self::assertSame(['url' => null, 'webcalUrl' => null], $this->address($alice));
        self::assertSame(404, $this->anonymous('GET', $second));
    }

    public function testTheAddressNeedsASignedInUserButTheFeedDoesNot(): void
    {
        self::assertSame(401, $this->anonymous('GET', '/api/training/calendar'));
        self::assertSame(401, $this->anonymous('POST', '/api/training/calendar'));
        self::assertSame(404, $this->anonymous('GET', '/api/calendar/'.str_repeat('a', 43).'.ics'));
        self::assertSame(404, $this->anonymous('GET', '/api/calendar/short.ics'));
    }

    public function testASessionDownloadsAsAnIcsFile(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $mallory = $this->createUserIn('mallory@example.com');
        $plan = $this->save($alice, ['title' => 'Matin échecs', 'repetition' => 'weekly', 'time' => '07:00', 'weekdays' => [3], 'reminderEnabled' => true, 'reminderChannels' => ['push'], 'reminderMinutes' => 10]);
        $onDemand = $this->save($alice, ['title' => 'Quand je veux']);

        $response = $this->api('GET', '/api/training/plans/'.$plan['id'].'/calendar.ics', $alice);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('attachment; filename=matin-echecs.ics', $response->headers->get('Content-Disposition'));
        $ics = (string) $response->getContent();
        self::assertStringContainsString('RRULE:FREQ=WEEKLY;BYDAY=WE', $ics);
        self::assertStringContainsString('TRIGGER:-PT10M', $ics);

        self::assertSame(404, $this->api('GET', '/api/training/plans/'.$plan['id'].'/calendar.ics', $mallory)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/training/plans/'.$onDemand['id'].'/calendar.ics', $alice)->getStatusCode());
    }

    /** Without credentials, as a calendar app asks. */
    private function anonymous(string $method, string $uri): int
    {
        $this->client->request($method, $uri);

        return $this->client->getResponse()->getStatusCode();
    }

    /**
     * @return array{url: string|null, webcalUrl: string|null}
     */
    private function address(User $user): array
    {
        $response = $this->api('GET', '/api/training/calendar', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{url: string|null, webcalUrl: string|null} */
        return $this->json($response);
    }

    /**
     * @return array{url: string|null, webcalUrl: string|null}
     */
    private function regenerate(User $user): array
    {
        $response = $this->api('POST', '/api/training/calendar', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{url: string|null, webcalUrl: string|null} */
        return $this->json($response);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{id: string}
     */
    private function save(User $user, array $body): array
    {
        $response = $this->api('POST', '/api/training/plans', $user, $body + ['steps' => [self::FREE]]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{id: string} */
        return $this->json($response);
    }
}
