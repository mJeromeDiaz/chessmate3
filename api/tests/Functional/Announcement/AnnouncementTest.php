<?php

declare(strict_types=1);

namespace App\Tests\Functional\Announcement;

use App\Announcement\Email\AnnouncementEmailDue;
use App\Announcement\Email\UnsubscribeToken;
use App\Entity\User;
use App\Enum\Locale;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * The team's announcements (docs/ANNOUNCEMENTS.md): written by an admin (HTML sanitized), shown
 * once to each active account between their start and end, in its language, and emailed once to
 * the active verified accounts that did not opt out, with a one-click unsubscribe link. The test
 * clock starts on Monday 28 September 2026, 10:00 UTC.
 */
final class AnnouncementTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private MockClock $clock;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->clock = new MockClock('2026-09-28 10:00:00', 'UTC');
        Clock::set($this->clock);
        $this->admin = $this->createUser('admin@example.com', admin: true);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testOnlyAdminsReachTheAdministration(): void
    {
        $alice = $this->createUser('alice@example.com');
        self::assertSame(403, $this->call($alice, 'GET', '/api/admin/announcements')->getStatusCode());
        self::assertSame(403, $this->call($alice, 'POST', '/api/admin/announcements', ['titleFr' => 'x', 'bodyFr' => 'x'])->getStatusCode());
        self::assertSame(403, $this->call($alice, 'GET', '/api/admin/announcements/email-recipients')->getStatusCode());
        self::assertSame(200, $this->call($this->admin, 'GET', '/api/admin/announcements')->getStatusCode());
        self::assertSame(404, $this->call($this->admin, 'PUT', '/api/admin/announcements/0192f6c4-0000-7000-8000-000000000000', ['titleFr' => 'x', 'bodyFr' => 'x'])->getStatusCode());
    }

    public function testTheFormIsCheckedAndTheHtmlSanitized(): void
    {
        $refused = $this->call($this->admin, 'POST', '/api/admin/announcements', [
            'titleFr' => '  ',
            'bodyFr' => '<script>alert(1)</script><br>',
            'titleEn' => 'Maintenance',
            'startsAt' => '2026-09-28T12:00:00Z',
            'endsAt' => '2026-09-28T11:00:00Z',
        ]);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['titleFr', 'bodyFr', 'bodyEn', 'endsAt'], array_column($this->listOf($this->json($refused)['violations']), 'propertyPath'));
        $past = $this->call($this->admin, 'POST', '/api/admin/announcements', ['titleFr' => 'x', 'bodyFr' => 'x', 'endsAt' => '2026-09-28T09:00:00Z']);
        self::assertSame(['endsAt'], array_column($this->listOf($this->json($past)['violations']), 'propertyPath'));

        $created = $this->call($this->admin, 'POST', '/api/admin/announcements', [
            'titleFr' => 'Nouveau module',
            'bodyFr' => '<p onclick="steal()">À <b>tester</b> <a href="https://example.com/x">ici</a> <a href="javascript:alert(1)">là</a><img src="https://example.com/i.png"></p><script>alert(1)</script>',
            'titleEn' => '',
            'bodyEn' => '<br>',
        ]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $view = $this->json($created);
        self::assertSame(['draft', 'draft', null, null], [$view['status'], $view['state'], $view['titleEn'], $view['bodyEn']], 'an empty English pair is none');
        self::assertIsString($view['bodyFr']);
        self::assertStringNotContainsString('script', $view['bodyFr']);
        self::assertStringNotContainsString('onclick', $view['bodyFr']);
        self::assertStringNotContainsString('javascript', $view['bodyFr']);
        self::assertStringNotContainsString('<img', $view['bodyFr']);
        self::assertStringContainsString('<b>tester</b>', $view['bodyFr']);
        self::assertStringContainsString('href="https://example.com/x"', $view['bodyFr']);
        self::assertStringContainsString('target="_blank"', $view['bodyFr']);
        self::assertStringContainsString('rel="noopener noreferrer"', $view['bodyFr']);
        self::assertSame('admin@example.com', $view['author']);
    }

    public function testAPublishedAnnouncementIsShownOnceBetweenItsStartAndItsEndInThePlayersLanguage(): void
    {
        $alice = $this->createUser('alice@example.com', locale: Locale::French);
        $bob = $this->createUser('bob@example.com', locale: Locale::English);

        $draft = $this->json($this->call($this->admin, 'POST', '/api/admin/announcements', $this->form(['startsAt' => '2026-09-28T11:00:00Z', 'endsAt' => '2026-09-28T15:00:00Z'])));
        self::assertSame([], $this->current($alice), 'a draft is shown to no one');
        $uri = '/api/admin/announcements/'.$this->string($draft['id']);
        $published = $this->json($this->call($this->admin, 'PUT', $uri, $this->form(['startsAt' => '2026-09-28T11:00:00Z', 'endsAt' => '2026-09-28T15:00:00Z', 'publish' => true])));
        self::assertSame(['published', 'scheduled'], [$published['status'], $published['state']]);
        self::assertSame([], $this->current($alice), 'not started');

        $this->clock->modify('+1 hour');
        $shown = $this->current($alice);
        self::assertSame([['Maintenance à 14 h', '<p>Le site sera <b>coupé</b> 10 min.</p>']], array_map(static fn (array $a): array => [$a['title'], $a['body']], $shown));
        self::assertSame('2026-09-28T11:00:00Z', $shown[0]['startsAt']);
        self::assertSame([['Maintenance at 2 pm', '<p>The site will be <b>down</b> for 10 min.</p>']], array_map(static fn (array $a): array => [$a['title'], $a['body']], $this->current($bob)), 'English when written');

        // Closed: never again, for that player only.
        self::assertSame(204, $this->call($alice, 'POST', '/api/announcements/'.$this->string($shown[0]['id']).'/read')->getStatusCode());
        self::assertSame(204, $this->call($alice, 'POST', '/api/announcements/'.$this->string($shown[0]['id']).'/read')->getStatusCode(), 'idempotent');
        self::assertSame(204, $this->call($alice, 'POST', '/api/announcements/0192f6c4-0000-7000-8000-000000000000/read')->getStatusCode(), 'unknown: nothing');
        self::assertSame([], $this->current($alice));
        self::assertCount(1, $this->current($bob));
        $list = $this->listOf($this->json($this->call($this->admin, 'GET', '/api/admin/announcements'))['announcements']);
        self::assertSame(['live', 1], [$list[0]['state'], $list[0]['reads']]);

        // An account created after the publication sees it too.
        $carol = $this->createUser('carol@example.com');
        self::assertCount(1, $this->current($carol));

        // Ended: shown to no one.
        $this->clock->modify('+4 hours');
        self::assertSame([], $this->current($bob));
        self::assertSame('ended', $this->listOf($this->json($this->call($this->admin, 'GET', '/api/admin/announcements'))['announcements'])[0]['state']);
    }

    public function testAnArchivedAnnouncementIsGoneAndNoLongerChanges(): void
    {
        $alice = $this->createUser('alice@example.com');
        $created = $this->json($this->call($this->admin, 'POST', '/api/admin/announcements', $this->form(['publish' => true])));
        self::assertSame('live', $created['state']);
        self::assertCount(1, $this->current($alice));
        $uri = '/api/admin/announcements/'.$this->string($created['id']);

        $archived = $this->json($this->call($this->admin, 'POST', $uri.'/archive'));
        self::assertSame(['archived', 'archived'], [$archived['status'], $archived['state']]);
        self::assertSame([], $this->current($alice));
        $refused = $this->call($this->admin, 'PUT', $uri, $this->form());
        self::assertSame(['status'], array_column($this->listOf($this->json($refused)['violations']), 'propertyPath'));
        self::assertSame(422, $this->call($this->admin, 'POST', $uri.'/email')->getStatusCode());
    }

    public function testTheEmailGoesOnceToTheActiveVerifiedAccountsThatDidNotOptOut(): void
    {
        $before = $this->json($this->call($this->admin, 'GET', '/api/admin/announcements/email-recipients'))['count'];
        self::assertIsInt($before);
        $alice = $this->createUser('alice@example.com', locale: Locale::English);
        $this->createUser('bob@example.com', verified: false);
        $carol = $this->createUser('carol@example.com');
        $carol->suspend($this->clock->now(), 'spam');
        $dave = $this->createUser('dave@example.com');
        $dave->setAnnouncementEmails(false);
        $this->entityManager->flush();
        self::assertSame($before + 1, $this->json($this->call($this->admin, 'GET', '/api/admin/announcements/email-recipients'))['count']);

        $draft = $this->json($this->call($this->admin, 'POST', '/api/admin/announcements', $this->form()));
        $uri = '/api/admin/announcements/'.$this->string($draft['id']);
        $refused = $this->call($this->admin, 'POST', $uri.'/email');
        self::assertSame(['email'], array_column($this->listOf($this->json($refused)['violations']), 'propertyPath'), 'a draft is not emailed');
        $this->call($this->admin, 'PUT', $uri, $this->form(['publish' => true]));

        $asked = $this->call($this->admin, 'POST', $uri.'/email');
        self::assertSame(200, $asked->getStatusCode(), (string) $asked->getContent());
        $email = $this->json($asked)['email'];
        self::assertIsArray($email);
        self::assertIsString($email['requestedAt']);
        // The first ones queued by the request, delivered twice; then a few each minute until
        // nobody is left. The kernel resets the in-memory queue and the mailer's log once it boots
        // again after a request (the first command): the emails are read before, then after.
        $this->deliver();
        $this->deliver();
        $sent = $this->emails();
        for ($i = 0; $i < 100 && $this->sendReminders() > 0; ++$i) {
            $this->clock->modify('+1 minute');
        }
        $this->deliver();
        $sent = array_merge($sent, $this->emails());

        $emails = array_values(array_filter($sent, static fn (Email $email): bool => \in_array($email->getTo()[0]->getAddress(), ['alice@example.com', 'bob@example.com', 'carol@example.com', 'dave@example.com'], true)));
        self::assertCount(1, $emails, 'alice only, once although delivered twice');
        $email = $emails[0];
        self::assertSame('alice@example.com', $email->getTo()[0]->getAddress());
        self::assertSame('Maintenance at 2 pm', $email->getSubject(), 'in the account\'s language');
        self::assertStringContainsString('<b>down</b>', (string) $email->getHtmlBody());
        self::assertStringContainsString('The site will be down for 10 min.', (string) $email->getTextBody());
        $token = self::getContainer()->get(UnsubscribeToken::class)->of($alice->getId());
        self::assertStringContainsString('/#/annonces/desinscription?u='.$alice->getId()->toRfc4122().'&amp;t='.$token, (string) $email->getHtmlBody());
        $header = $email->getHeaders()->get('List-Unsubscribe')?->getBodyAsString();
        self::assertIsString($header);
        self::assertStringContainsString('/api/announcements/unsubscribe?u='.$alice->getId()->toRfc4122().'&t='.$token, $header);
        self::assertSame('List-Unsubscribe=One-Click', $email->getHeaders()->get('List-Unsubscribe-Post')?->getBodyAsString());
        self::assertSame(422, $this->call($this->admin, 'POST', $uri.'/email')->getStatusCode(), 'once');

        $view = $this->listOf($this->json($this->call($this->admin, 'GET', '/api/admin/announcements'))['announcements'])[0];
        self::assertIsArray($view['email']);
        self::assertIsString($view['email']['finishedAt']);
        self::assertSame($before + 1, $view['email']['queued']);
    }

    public function testTheEmailOfAScheduledAnnouncementWaitsForItsStart(): void
    {
        $this->createUser('alice@example.com');
        $created = $this->json($this->call($this->admin, 'POST', '/api/admin/announcements', $this->form(['startsAt' => '2026-09-28T12:00:00Z', 'publish' => true])));
        $uri = '/api/admin/announcements/'.$this->string($created['id']);
        self::assertSame(200, $this->call($this->admin, 'POST', $uri.'/email')->getStatusCode());
        self::assertSame(0, $this->sendReminders());
        self::assertSame(0, $this->queuedEmails());

        $this->clock->modify('+2 hours');
        self::assertGreaterThan(0, $this->sendReminders());
        self::assertGreaterThan(0, $this->queuedEmails());
    }

    public function testTheUnsubscribeLinkTurnsTheEmailsOffWithoutASession(): void
    {
        $alice = $this->createUser('alice@example.com');
        $id = $alice->getId()->toRfc4122();
        $token = self::getContainer()->get(UnsubscribeToken::class)->of($alice->getId());

        $this->client->request('POST', '/api/announcements/unsubscribe?'.http_build_query(['u' => $id, 't' => str_repeat('0', 64)]));
        $wrongToken = $this->client->getResponse();
        self::assertSame(400, $wrongToken->getStatusCode());
        $this->client->request('POST', '/api/announcements/unsubscribe', server: ['CONTENT_TYPE' => 'application/json'], content: '{"u": "nope", "t": "x"}');
        $wrongId = $this->client->getResponse();
        self::assertSame(400, $wrongId->getStatusCode());

        // The mail client's one-click POST (RFC 8058).
        $this->client->request('POST', '/api/announcements/unsubscribe?'.http_build_query(['u' => $id, 't' => $token]), ['List-Unsubscribe' => 'One-Click']);
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        $profile = $this->json($this->call($alice, 'GET', '/api/profile'));
        self::assertFalse($profile['announcementEmails']);

        // The SPA's page, again: still off.
        $this->client->request('POST', '/api/announcements/unsubscribe', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['u' => $id, 't' => $token], \JSON_THROW_ON_ERROR));
        self::assertSame(204, $this->client->getResponse()->getStatusCode());
        $this->entityManager->clear();
        self::assertFalse($this->entityManager->find(User::class, $alice->getId())?->hasAnnouncementEmails());
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return array<string, mixed>
     */
    private function form(array $fields = []): array
    {
        return $fields + [
            'titleFr' => 'Maintenance à 14 h',
            'bodyFr' => '<p>Le site sera <b>coupé</b> 10 min.</p>',
            'titleEn' => 'Maintenance at 2 pm',
            'bodyEn' => '<p>The site will be <b>down</b> for 10 min.</p>',
        ];
    }

    /**
     * Asked again each time: what the clock and the receipts allow now.
     *
     * @return list<array<string, mixed>>
     *
     * @phpstan-impure
     */
    private function current(User $user): array
    {
        $response = $this->call($user, 'GET', '/api/announcements');
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->listOf($this->json($response)['announcements']);
    }

    private function sendReminders(): int
    {
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException('No kernel.')))->find('app:training:send-reminders'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        preg_match('/(\d+) announcement email/', $tester->getDisplay(), $m);

        return (int) ($m[1] ?? -1);
    }

    private function queuedEmails(): int
    {
        return \count(array_filter($this->transport()->getSent(), static fn (object $envelope): bool => $envelope->getMessage() instanceof AnnouncementEmailDue));
    }

    /** Runs the announcement emails queued on the async transport, as the worker would. */
    private function deliver(): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ($this->transport()->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof AnnouncementEmailDue) {
                $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            }
        }
    }

    private function transport(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }

    /**
     * @return list<Email> the emails sent (rendered)
     */
    private function emails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn (object $m): bool => $m instanceof Email));
    }

    private function createUser(string $email, bool $admin = false, bool $verified = true, ?Locale $locale = null): User
    {
        $user = new User();
        $user->setEmail($email);
        if ($verified) {
            $user->markEmailVerified();
        }
        if ($admin) {
            $user->setRoles(['ROLE_ADMIN']);
        }
        $user->setLocale($locale);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(User $user, string $method, string $uri, ?array $body = null): Response
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listOf(mixed $value): array
    {
        self::assertIsArray($value);

        /** @var list<array<string, mixed>> */
        return array_values($value);
    }

    private function string(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }
}
