<?php

declare(strict_types=1);

namespace App\Tests\Functional\EarlyAccess;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * The waiting list ("Demander l'accès", docs/EARLY_ACCESS.md): a public request that says nothing
 * about anyone and emails no one, and the admin side (list, invite, delete).
 *
 * @phpstan-type RequestJson array{id: string, email: string, createdAt: string, invitedAt: string|null, hasAccount: bool, invitation: array{id: string, status: string, keyHint: string}|null, key: string|null}
 */
final class AccessRequestTest extends EarlyAccessWebTestCase
{
    public function testTheAnswerIsTheSameWhateverTheAddressAndNothingIsEmailed(): void
    {
        $this->createUser('member@example.com');

        $new = $this->ask('New@Example.com');
        $again = $this->ask('new@example.com');
        $member = $this->ask('member@example.com');

        foreach ([$new, $again, $member] as $response) {
            self::assertSame(202, $response->getStatusCode());
            self::assertSame((string) $new->getContent(), (string) $response->getContent());
        }
        self::assertSame([], $this->async()->getSent());
        self::assertSame([], $this->emails());
        self::assertSame(['new@example.com', 'member@example.com'], $this->storedEmails(), 'One row per address, lowercased.');
    }

    public function testAnInvalidAddressIsRefusedAndAFilledHoneypotIsDropped(): void
    {
        self::assertSame(422, $this->ask('not-an-email')->getStatusCode());
        self::assertSame(422, $this->ask('')->getStatusCode());
        self::assertSame(422, $this->ask(str_repeat('a', 175).'@example.com')->getStatusCode());

        $bot = $this->ask('bot@example.com', 'https://spam.example');

        self::assertSame(202, $bot->getStatusCode());
        self::assertSame([], $this->storedEmails());
    }

    public function testRequestsAreLimitedToFiveAnHourPerIp(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(202, $this->ask("guest$i@example.com")->getStatusCode());
        }

        self::assertSame(429, $this->ask('guest5@example.com')->getStatusCode());
        self::assertCount(5, $this->storedEmails());
    }

    public function testAdminsListTheRequestsInOrderOfArrival(): void
    {
        $this->createUser('member@example.com');
        $this->ask('first@example.com');
        $this->ask('member@example.com');
        $this->ask('third@example.com');

        $all = $this->list();
        self::assertSame(['first@example.com', 'member@example.com', 'third@example.com'], array_column($all, 'email'));
        self::assertSame([false, true, false], array_column($all, 'hasAccount'));
        self::assertNull($all[0]['invitedAt']);
        self::assertNull($all[0]['invitation']);
        self::assertNull($all[0]['key']);

        $this->api('POST', '/api/admin/access-requests/'.$all[0]['id'].'/invite', $this->admin);
        self::assertSame(['first@example.com'], array_column($this->list('?invited=true'), 'email'));
        self::assertSame(['member@example.com', 'third@example.com'], array_column($this->list('?invited=false'), 'email'));
        self::assertSame(400, $this->api('GET', '/api/admin/access-requests?invited=maybe', $this->admin)->getStatusCode());
    }

    public function testInvitingARequestEmailsAKeyOnce(): void
    {
        $this->ask('guest@example.com');
        $request = $this->list()[0];

        $response = $this->api('POST', '/api/admin/access-requests/'.$request['id'].'/invite', $this->admin);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var RequestJson $invited */
        $invited = $this->json($response);
        self::assertIsString($invited['key']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $invited['key']);
        self::assertSame('2026-10-05T10:00:00+00:00', $invited['invitedAt']);
        self::assertNotNull($invited['invitation']);
        self::assertSame('pending', $invited['invitation']['status']);
        self::assertSame(substr($invited['key'], 0, 4), $invited['invitation']['keyHint']);

        $this->deliver();
        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertSame('guest@example.com', $emails[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('/#/register?key='.$invited['key'], (string) $emails[0]->getTextBody());

        $invitation = $this->invitation($invited['invitation']['id']);
        self::assertSame('guest@example.com', $invitation['email']);
        self::assertSame('2026-10-12T10:00:00+00:00', $invitation['expiresAt'], '7 days.');
        self::assertTrue($invitation['logs'][0]['details']['fromRequest'] ?? false);

        // The list never shows the key again, and a second invite is refused.
        self::assertNull($this->list()[0]['key']);
        self::assertSame(409, $this->api('POST', '/api/admin/access-requests/'.$request['id'].'/invite', $this->admin)->getStatusCode());
        self::assertSame(1, $this->countInvitations());
    }

    public function testDeletingARequestKeepsItsInvitation(): void
    {
        $this->ask('guest@example.com');
        $request = $this->list()[0];
        $this->api('POST', '/api/admin/access-requests/'.$request['id'].'/invite', $this->admin);

        self::assertSame(204, $this->api('DELETE', '/api/admin/access-requests/'.$request['id'], $this->admin)->getStatusCode());

        self::assertSame([], $this->storedEmails());
        self::assertSame(1, $this->countInvitations());
        self::assertSame(404, $this->api('DELETE', '/api/admin/access-requests/'.$request['id'], $this->admin)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/admin/access-requests/'.$request['id'].'/invite', $this->admin)->getStatusCode());
    }

    public function testOnlyAdminsReachTheRequests(): void
    {
        $this->ask('guest@example.com');
        $request = $this->list()[0];
        $player = $this->createUser('player@example.com');

        foreach ([
            ['GET', '/api/admin/access-requests'],
            ['POST', '/api/admin/access-requests/'.$request['id'].'/invite'],
            ['DELETE', '/api/admin/access-requests/'.$request['id']],
        ] as [$method, $uri]) {
            self::assertSame(401, $this->api($method, $uri)->getStatusCode(), "$method $uri anonymous");
            self::assertSame(403, $this->api($method, $uri, $player)->getStatusCode(), "$method $uri as a player");
        }
        self::assertSame(0, $this->countInvitations());
        self::assertSame(['guest@example.com'], $this->storedEmails());
    }

    private function ask(string $email, ?string $website = null): Response
    {
        $this->client->request('POST', '/api/auth/invitation/request', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => $email, 'website' => $website], \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /**
     * @return list<RequestJson>
     */
    private function list(string $query = ''): array
    {
        $response = $this->api('GET', '/api/admin/access-requests'.$query, $this->admin);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $member = $this->json($response)['member'] ?? null;
        self::assertIsArray($member);

        /** @var list<RequestJson> */
        return $member;
    }

    /**
     * @return list<string> in order of arrival
     */
    private function storedEmails(): array
    {
        /** @var list<string> */
        return self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT email FROM early_access_request ORDER BY id');
    }
}
