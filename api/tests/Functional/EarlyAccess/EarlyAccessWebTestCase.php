<?php

declare(strict_types=1);

namespace App\Tests\Functional\EarlyAccess;

use App\EarlyAccess\Invitation\Message\SendInvitationEmail;
use App\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * Early access tests (docs/EARLY_ACCESS.md): an admin, plain users, a controllable clock, and the
 * invitation emails delivered as the worker would.
 *
 * @phpstan-type Account array{id: string, email: string|null, handle: string|null}
 * @phpstan-type InvitationJson array{id: string, email: string, status: string, key: string|null, keyHint: string, createdAt: string, createdBy: Account|null, expiresAt: string|null, usedAt: string|null, usedBy: Account|null, revokedAt: string|null, emailStatus: string, emailSentAt: string|null, sendCount: int, logs: list<array{id: string, action: string, actor: Account|null, details: array<string, mixed>, createdAt: string}>|null}
 */
abstract class EarlyAccessWebTestCase extends WebTestCase
{
    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected MockClock $clock;
    protected User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->clock = new MockClock('2026-10-05 10:00:00', 'UTC');
        Clock::set($this->clock);
        $this->admin = $this->createUser('admin@example.com', admin: true);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    protected function createUser(string $email, bool $admin = false): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->markEmailVerified();
        $user->setTimezone('Europe/Paris');
        if ($admin) {
            $user->setRoles(['ROLE_ADMIN']);
        }
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    protected function api(string $method, string $uri, ?User $user = null, ?array $body = null): Response
    {
        $server = ['HTTP_ACCEPT' => 'application/ld+json', 'CONTENT_TYPE' => 'application/ld+json'];
        if (null !== $user) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        }
        $this->client->request($method, $uri, server: $server, content: null === $body ? null : json_encode($body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    protected function json(Response $response): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return InvitationJson
     */
    protected function invite(string $email = 'guest@example.com', array $body = []): array
    {
        $response = $this->api('POST', '/api/admin/invitation-keys', $this->admin, $body + ['email' => $email]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var InvitationJson */
        return $this->json($response);
    }

    /**
     * @return InvitationJson
     */
    protected function invitation(string $id): array
    {
        $response = $this->api('GET', '/api/admin/invitation-keys/'.$id, $this->admin);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var InvitationJson */
        return $this->json($response);
    }

    /**
     * The invitation emails queued by the last request, handled as the worker would.
     */
    protected function deliver(): void
    {
        $this->handle(...$this->queued());
    }

    /**
     * Handles invitation emails as the worker would, e.g. ones captured before later requests.
     */
    protected function handle(SendInvitationEmail ...$messages): void
    {
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ($messages as $message) {
            $bus->dispatch(new Envelope($message, [new ReceivedStamp('async')]));
        }
        $this->async()->reset();
    }

    /**
     * The in-memory transport only holds what the LAST request queued: the client reboots the
     * kernel (a new transport) before each request. Capture messages before the next request.
     *
     * @return list<SendInvitationEmail>
     */
    protected function queued(): array
    {
        $messages = [];
        foreach ($this->async()->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof SendInvitationEmail) {
                $messages[] = $message;
            }
        }

        return $messages;
    }

    /**
     * @return list<Email> the emails sent (rendered)
     */
    protected function emails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn (object $m): bool => $m instanceof Email));
    }

    protected function countInvitations(): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM early_access_invitation_key');
        self::assertIsNumeric($count);

        return (int) $count;
    }

    protected function async(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
