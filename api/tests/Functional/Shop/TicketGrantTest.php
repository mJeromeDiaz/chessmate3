<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gift wheel tickets given by an admin (docs/SHOP.md, § 4 quater): to one player or to every
 * active account, in the stock at once, their message shown to the player until marked seen.
 */
final class TicketGrantTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->admin = $this->createUser('admin@example.com', true);
    }

    public function testTicketsAreGivenToAPlayerWithAMessageShownUntilSeen(): void
    {
        $alice = $this->createUser('alice@example.com');
        $uri = '/api/admin/shop/players/'.$alice->getId()->toRfc4122().'/wheel-tickets';

        $refused = $this->call($this->admin, 'POST', $uri, ['quantity' => 0, 'message' => '   ']);
        self::assertSame(422, $refused->getStatusCode());
        self::assertSame(['quantity', 'message'], array_column($this->listOf($this->json($refused)['violations']), 'propertyPath'));
        self::assertSame(422, $this->call($this->admin, 'POST', $uri, ['quantity' => 101, 'message' => 'Trop'])->getStatusCode());
        self::assertSame(422, $this->call($this->admin, 'POST', $uri, ['quantity' => 1, 'message' => str_repeat('x', 256)])->getStatusCode());
        self::assertSame(404, $this->call($this->admin, 'POST', '/api/admin/shop/players/0192f6c4-0000-7000-8000-000000000000/wheel-tickets', ['quantity' => 1, 'message' => 'x'])->getStatusCode());

        $given = $this->call($this->admin, 'POST', $uri, ['quantity' => 2, 'message' => 'Merci pour ton retour !']);
        self::assertSame(200, $given->getStatusCode(), (string) $given->getContent());
        self::assertSame(['wheel_ticket' => 2], $this->json($given)['inventory']);
        $detail = $this->json($this->call($this->admin, 'POST', $uri, ['quantity' => 3, 'message' => 'Joyeux Noël !']));
        self::assertSame(['wheel_ticket' => 5], $detail['inventory'], 'the stock adds up');
        $grants = $this->listOf($detail['grants']);
        self::assertSame(
            [[3, 'Joyeux Noël !', 'admin@example.com', false, null], [2, 'Merci pour ton retour !', 'admin@example.com', false, null]],
            array_map(static fn (array $g): array => [$g['quantity'], $g['message'], $g['admin'], $g['everyone'], $g['seenAt']], $grants),
        );
        self::assertSame([], $this->listOf($detail['ledger']), 'no crystal moved');

        // The player sees both messages, the oldest first, and holds the tickets.
        $store = $this->json($this->call($alice, 'GET', '/api/shop'));
        self::assertSame(['wheel_ticket' => 5], $store['inventory']);
        $gifts = $this->listOf($store['gifts']);
        self::assertSame(
            [['wheel_ticket', 2, 'Merci pour ton retour !'], ['wheel_ticket', 3, 'Joyeux Noël !']],
            array_map(static fn (array $g): array => [$g['item'], $g['quantity'], $g['message']], $gifts),
        );

        // Another player cannot mark them seen; the player can, one or all.
        $bob = $this->createUser('bob@example.com');
        self::assertSame(204, $this->call($bob, 'POST', '/api/shop/gifts/seen', ['ids' => [$gifts[0]['id']]])->getStatusCode());
        self::assertCount(2, $this->listOf($this->json($this->call($alice, 'GET', '/api/shop'))['gifts']));
        self::assertSame(422, $this->call($alice, 'POST', '/api/shop/gifts/seen', ['ids' => []])->getStatusCode());
        self::assertSame(422, $this->call($alice, 'POST', '/api/shop/gifts/seen', ['ids' => ['nope']])->getStatusCode());
        self::assertSame(204, $this->call($alice, 'POST', '/api/shop/gifts/seen', ['ids' => [$gifts[0]['id']]])->getStatusCode());
        self::assertSame([$gifts[1]['id']], array_column($this->listOf($this->json($this->call($alice, 'GET', '/api/shop'))['gifts']), 'id'));
        self::assertSame(204, $this->call($alice, 'POST', '/api/shop/gifts/seen', ['ids' => array_column($gifts, 'id')])->getStatusCode(), 'idempotent');
        self::assertSame([], $this->json($this->call($alice, 'GET', '/api/shop'))['gifts']);
        $seen = $this->listOf($this->json($this->call($this->admin, 'GET', '/api/admin/shop/players/'.$alice->getId()->toRfc4122()))['grants']);
        self::assertIsString($seen[0]['seenAt']);

        // A ticket given spins the wheel.
        $spin = $this->call($alice, 'POST', '/api/shop/wheel/spins', []);
        self::assertSame(200, $spin->getStatusCode(), (string) $spin->getContent());
        self::assertSame([true, 4], [$this->json($spin)['ticket'], $this->json($spin)['tickets']]);
    }

    public function testTicketsAreGivenToEveryActiveAccountWithAJournalLine(): void
    {
        $before = $this->json($this->call($this->admin, 'GET', '/api/admin/shop/wheel-tickets/recipients'))['count'];
        self::assertIsInt($before);
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $carol = $this->createUser('carol@example.com');
        $carol->suspend(new \DateTimeImmutable(), 'Triche');
        $dave = $this->createUser('dave@example.com');
        $dave->scheduleDeletion(new \DateTimeImmutable('+30 days'));
        $this->entityManager->flush();
        self::assertSame($before + 2, $this->json($this->call($this->admin, 'GET', '/api/admin/shop/wheel-tickets/recipients'))['count'], 'neither suspended nor being deleted');
        // Bob already holds one.
        $this->call($this->admin, 'POST', '/api/admin/shop/players/'.$bob->getId()->toRfc4122().'/wheel-tickets', ['quantity' => 1, 'message' => 'Bienvenue']);

        self::assertSame(422, $this->call($this->admin, 'POST', '/api/admin/shop/wheel-tickets', ['quantity' => 2])->getStatusCode(), 'a message is required');
        $given = $this->call($this->admin, 'POST', '/api/admin/shop/wheel-tickets', ['quantity' => 2, 'message' => 'Joyeux Noël à tous !']);
        self::assertSame(201, $given->getStatusCode(), (string) $given->getContent());
        $batch = $this->json($given);
        self::assertSame(['wheel_ticket', 2, 'Joyeux Noël à tous !', $before + 2, 'admin@example.com'], [$batch['item'], $batch['quantity'], $batch['message'], $batch['recipients'], $batch['admin']]);
        self::assertSame([$batch], $this->json($this->call($this->admin, 'GET', '/api/admin/shop/grant-batches'))['batches']);

        $inventory = fn (User $user): mixed => $this->json($this->call($this->admin, 'GET', '/api/admin/shop/players/'.$user->getId()->toRfc4122()))['inventory'];
        self::assertSame(['wheel_ticket' => 2], $inventory($alice));
        self::assertSame(['wheel_ticket' => 3], $inventory($bob));
        self::assertSame([], $inventory($carol));
        self::assertSame([], $inventory($dave));
        self::assertSame(['wheel_ticket' => 2], $inventory($this->admin), 'the admin is an active account too');

        $grants = $this->listOf($this->json($this->call($this->admin, 'GET', '/api/admin/shop/players/'.$bob->getId()->toRfc4122()))['grants']);
        self::assertSame([[2, true], [1, false]], array_map(static fn (array $g): array => [$g['quantity'], $g['everyone']], $grants));
        self::assertSame(
            [['wheel_ticket', 2, 'Joyeux Noël à tous !']],
            array_map(static fn (array $g): array => [$g['item'], $g['quantity'], $g['message']], $this->listOf($this->json($this->call($alice, 'GET', '/api/shop'))['gifts'])),
        );
    }

    private function createUser(string $email, bool $admin = false): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->markEmailVerified();
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
    private function call(User $user, string $method, string $uri, ?array $body = null): Response
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/ld+json',
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
}
