<?php

declare(strict_types=1);

namespace App\Tests\Functional\EarlyAccess;

use App\EarlyAccess\Invitation\KeyGenerator;
use App\EarlyAccess\Invitation\Message\SendInvitationEmail;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;

/**
 * Invitation keys on the admin side (docs/EARLY_ACCESS.md): creation and its email, list and
 * filters, resend (a new key), revocation, access and rate limiting.
 *
 * @phpstan-import-type InvitationJson from EarlyAccessWebTestCase
 */
final class InvitationKeyTest extends EarlyAccessWebTestCase
{
    public function testCreatingAnInvitationEmailsItsKeyAndStoresOnlyItsHash(): void
    {
        $invitation = $this->invite('Guest@Example.com');

        self::assertSame('guest@example.com', $invitation['email']);
        self::assertSame('pending', $invitation['status']);
        self::assertIsString($invitation['key']);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9]{32}$/', $invitation['key']);
        self::assertSame(substr($invitation['key'], 0, 4), $invitation['keyHint']);
        self::assertSame('2026-10-12T10:00:00+00:00', $invitation['expiresAt'], '7 days by default.');
        self::assertSame('admin@example.com', $invitation['createdBy']['email'] ?? null);
        self::assertSame('pending', $invitation['emailStatus']);
        self::assertSame(1, $invitation['sendCount']);

        $stored = self::getContainer()->get(Connection::class)->fetchAssociative('SELECT key_hash, key_hint FROM early_access_invitation_key');
        self::assertIsArray($stored);
        self::assertSame(KeyGenerator::hash($invitation['key']), $stored['key_hash']);
        // Neither the row nor the queued message holds the key in clear.
        $message = $this->queued()[0] ?? null;
        self::assertInstanceOf(SendInvitationEmail::class, $message);
        self::assertStringNotContainsString($invitation['key'], $message->encryptedKey);

        $this->deliver();
        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertSame('guest@example.com', $emails[0]->getTo()[0]->getAddress());
        self::assertStringContainsString('/#/register?key='.$invitation['key'], (string) $emails[0]->getTextBody());
        self::assertStringContainsString('12/10/2026 à 12:00 (Europe/Paris)', (string) $emails[0]->getTextBody(), 'In the admin\'s time zone.');

        $detail = $this->invitation($invitation['id']);
        self::assertNull($detail['key'], 'The key is shown once.');
        self::assertSame('sent', $detail['emailStatus']);
        self::assertSame('2026-10-05T10:00:00+00:00', $detail['emailSentAt']);
        self::assertSame(['key_created', 'key_sent'], array_column($detail['logs'] ?? [], 'action'));
    }

    public function testTheExpiryIsChosenOrRemovedButNeverInThePast(): void
    {
        self::assertSame('2026-10-20T12:00:00+00:00', $this->invite(body: ['expiresAt' => '2026-10-20T14:00:00+02:00'])['expiresAt']);
        self::assertNull($this->invite(body: ['neverExpires' => true])['expiresAt']);

        foreach ([
            ['email' => 'guest@example.com', 'expiresAt' => '2026-10-05T09:59:00Z'],
            ['email' => 'guest@example.com', 'expiresAt' => '2027-10-06T10:00:00Z'],
            ['email' => 'not-an-email'],
            ['email' => ''],
            ['email' => str_repeat('a', 175).'@example.com'],
        ] as $body) {
            self::assertSame(422, $this->api('POST', '/api/admin/invitation-keys', $this->admin, $body)->getStatusCode(), (string) json_encode($body));
        }
        self::assertSame(2, $this->countInvitations());
    }

    public function testTheListFiltersByStatusEmailAndCreationDate(): void
    {
        $alice = $this->invite('alice@example.com');
        $this->clock->modify('+1 day');
        $bob = $this->invite('bob@example.com', ['expiresAt' => '2026-10-07T10:00:00Z']);
        $carol = $this->invite('carol@other.org');
        self::assertSame(204, $this->api('DELETE', '/api/admin/invitation-keys/'.$carol['id'], $this->admin)->getStatusCode());
        $this->clock->modify('+2 days');

        self::assertSame([$carol['id'], $bob['id'], $alice['id']], $this->ids(''));
        self::assertSame([$alice['id']], $this->ids('?status=pending'));
        self::assertSame([$bob['id']], $this->ids('?status=expired'));
        self::assertSame([$carol['id']], $this->ids('?status=revoked'));
        self::assertSame([], $this->ids('?status=used'));
        self::assertSame([$bob['id'], $alice['id']], $this->ids('?email=EXAMPLE.com'));
        self::assertSame([], $this->ids('?email=%25'), 'A wildcard is searched literally.');
        self::assertSame([$carol['id'], $bob['id']], $this->ids('?createdAfter=2026-10-06T00:00:00Z'));
        self::assertSame([$alice['id']], $this->ids('?createdBefore=2026-10-06T00:00:00Z'));

        $page = $this->json($this->api('GET', '/api/admin/invitation-keys?itemsPerPage=2&page=2', $this->admin));
        self::assertSame(3, $page['totalItems']);
        self::assertIsArray($page['member'] ?? null);
        self::assertSame([$alice['id']], array_column($page['member'], 'id'));

        self::assertSame(400, $this->api('GET', '/api/admin/invitation-keys?status=lost', $this->admin)->getStatusCode());
        self::assertSame(400, $this->api('GET', '/api/admin/invitation-keys?createdAfter=yesterday-ish', $this->admin)->getStatusCode());
    }

    public function testResendingReplacesTheKey(): void
    {
        $first = $this->invite();
        $stale = $this->queued();
        self::assertCount(1, $stale);
        $response = $this->api('POST', '/api/admin/invitation-keys/'.$first['id'].'/resend', $this->admin);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var InvitationJson $second */
        $second = $this->json($response);
        self::assertIsString($second['key']);
        self::assertNotSame($first['key'], $second['key']);
        self::assertSame(2, $second['sendCount']);
        self::assertSame($first['expiresAt'], $second['expiresAt'], 'A pending invitation keeps its expiry.');

        // The first email, still queued, is dropped: its key no longer works.
        $this->handle(...$stale, ...$this->queued());
        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertStringContainsString('key='.$second['key'], (string) $emails[0]->getTextBody());

        // An expired invitation gets 7 days again.
        $this->clock->modify('+8 days');
        self::assertSame('expired', $this->invitation($first['id'])['status']);
        /** @var InvitationJson $third */
        $third = $this->json($this->api('POST', '/api/admin/invitation-keys/'.$first['id'].'/resend', $this->admin));
        self::assertSame('pending', $third['status']);
        self::assertSame('2026-10-20T10:00:00+00:00', $third['expiresAt']);
        self::assertSame(['key_created', 'key_resent', 'key_sent', 'key_resent'], array_column($this->invitation($first['id'])['logs'] ?? [], 'action'));
    }

    public function testRevokingIsIdempotentAndEndsTheInvitation(): void
    {
        $invitation = $this->invite();
        $queued = $this->queued();
        self::assertCount(1, $queued);
        $revoked = $this->api('DELETE', '/api/admin/invitation-keys/'.$invitation['id'], $this->admin);
        self::assertSame(204, $revoked->getStatusCode());
        $revokedAgain = $this->api('DELETE', '/api/admin/invitation-keys/'.$invitation['id'], $this->admin);
        self::assertSame(204, $revokedAgain->getStatusCode());

        $detail = $this->invitation($invitation['id']);
        self::assertSame('revoked', $detail['status']);
        self::assertSame('2026-10-05T10:00:00+00:00', $detail['revokedAt']);
        self::assertSame(['key_created', 'key_revoked'], array_column($detail['logs'] ?? [], 'action'));

        // Its queued email is not sent, and it cannot be resent.
        $this->handle(...$queued);
        self::assertSame([], $this->emails());
        self::assertSame(409, $this->api('POST', '/api/admin/invitation-keys/'.$invitation['id'].'/resend', $this->admin)->getStatusCode());

        $unknown = '/api/admin/invitation-keys/01890000-0000-7000-8000-000000000000';
        self::assertSame(404, $this->api('GET', $unknown, $this->admin)->getStatusCode());
        self::assertSame(404, $this->api('DELETE', $unknown, $this->admin)->getStatusCode());
        self::assertSame(404, $this->api('POST', $unknown.'/resend', $this->admin)->getStatusCode());
    }

    public function testAFailedDeliveryIsShownOnceTheRetriesAreSpent(): void
    {
        $invitation = $this->invite();
        $message = $this->queued()[0] ?? null;
        self::assertNotNull($message);
        $dispatcher = self::getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);

        // A failure that will be retried changes nothing.
        $dispatcher->dispatch(new WorkerMessageFailedEvent(new Envelope($message), 'async', new TransportException('down')));
        $retried = $this->invitation($invitation['id']);
        self::assertSame('pending', $retried['emailStatus']);

        $dispatcher->dispatch(new WorkerMessageFailedEvent(new Envelope($message, [new RedeliveryStamp(3)]), 'async', new TransportException('down')));
        $detail = $this->invitation($invitation['id']);
        self::assertSame('failed', $detail['emailStatus']);
        self::assertSame(['key_created', 'key_send_failed'], array_column($detail['logs'] ?? [], 'action'));
    }

    public function testOnlyAdminsReachTheInvitations(): void
    {
        $invitation = $this->invite();
        $player = $this->createUser('player@example.com');

        foreach ([
            ['GET', '/api/admin/invitation-keys'],
            ['GET', '/api/admin/invitation-keys/'.$invitation['id']],
            ['POST', '/api/admin/invitation-keys'],
            ['POST', '/api/admin/invitation-keys/'.$invitation['id'].'/resend'],
            ['DELETE', '/api/admin/invitation-keys/'.$invitation['id']],
        ] as [$method, $uri]) {
            $body = 'POST' === $method ? ['email' => 'x@example.com'] : null;
            self::assertSame(401, $this->api($method, $uri, null, $body)->getStatusCode(), "$method $uri anonymous");
            self::assertSame(403, $this->api($method, $uri, $player, $body)->getStatusCode(), "$method $uri as a player");
        }
        self::assertSame(1, $this->countInvitations());
    }

    public function testCreationsAndResendsAreLimitedToTenAMinutePerAdmin(): void
    {
        $invitation = $this->invite('guest0@example.com');
        for ($i = 1; $i < 9; ++$i) {
            $this->invite("guest$i@example.com");
        }
        self::assertSame(200, $this->api('POST', '/api/admin/invitation-keys/'.$invitation['id'].'/resend', $this->admin)->getStatusCode());
        self::assertSame(429, $this->api('POST', '/api/admin/invitation-keys', $this->admin, ['email' => 'one-too-many@example.com'])->getStatusCode());
        self::assertSame(429, $this->api('POST', '/api/admin/invitation-keys/'.$invitation['id'].'/resend', $this->admin)->getStatusCode());

        // Another admin has their own allowance.
        $other = $this->createUser('other-admin@example.com', admin: true);
        self::assertSame(201, $this->api('POST', '/api/admin/invitation-keys', $other, ['email' => 'guest@example.com'])->getStatusCode());
    }

    public function testTheProfileTellsWhetherTheUserIsAnAdmin(): void
    {
        self::assertTrue($this->json($this->api('GET', '/api/profile', $this->admin))['isAdmin']);
        self::assertFalse($this->json($this->api('GET', '/api/profile', $this->createUser('player@example.com')))['isAdmin']);
    }

    /**
     * @return list<string> the ids listed, in order
     */
    private function ids(string $query): array
    {
        $response = $this->api('GET', '/api/admin/invitation-keys'.$query, $this->admin);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $member = $this->json($response)['member'] ?? [];
        self::assertIsArray($member);

        /** @var list<string> */
        return array_column($member, 'id');
    }
}
