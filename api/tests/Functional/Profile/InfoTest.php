<?php

declare(strict_types=1);

namespace App\Tests\Functional\Profile;

use App\Entity\User;
use App\Enum\Avatar;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Display name, handle and avatar of the profile (PUT /api/profile/info, handle availability). */
final class InfoTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testTheInfoIsEmptyUntilChosenThenKept(): void
    {
        $server = $this->signedIn('alice@example.com');

        $this->client->request('GET', '/api/profile', server: $server);
        $profile = $this->decode();
        self::assertNull($profile['displayName']);
        self::assertNull($profile['handle']);
        self::assertNull($profile['avatar']);

        $this->put($server, ['displayName' => '  Léa Moreau ', 'handle' => ' Lea_Echecs', 'avatar' => 'queen']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $profile = $this->decode();
        self::assertSame('Léa Moreau', $profile['displayName']);
        self::assertSame('lea_echecs', $profile['handle']);
        self::assertSame('queen', $profile['avatar']);

        self::getContainer()->get(EntityManagerInterface::class)->clear();
        $user = self::getContainer()->get(UserRepository::class)->findOneByEmail('alice@example.com');
        self::assertNotNull($user);
        self::assertSame('lea_echecs', $user->getHandle());
        self::assertSame(Avatar::Queen, $user->getAvatar());
    }

    public function testEmptyOrMissingFieldsClearThem(): void
    {
        $server = $this->signedIn('alice@example.com');
        $this->put($server, ['displayName' => 'Léa', 'handle' => 'lea', 'avatar' => 'pawn']);

        $this->put($server, ['displayName' => ' ', 'handle' => '']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        $profile = $this->decode();
        self::assertNull($profile['displayName']);
        self::assertNull($profile['handle']);
        self::assertNull($profile['avatar']);
    }

    public function testInvalidValuesAreRefused(): void
    {
        $server = $this->signedIn('alice@example.com');

        foreach (['ab', 'léa', 'lea-echecs', 'lea echecs', str_repeat('a', 21)] as $handle) {
            $this->put($server, ['handle' => $handle]);
            self::assertSame(422, $this->client->getResponse()->getStatusCode(), $handle);
            self::assertSame('invalid_handle', $this->decode()['error'], $handle);
        }

        $this->put($server, ['handle' => 'Admin']);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertSame('reserved_handle', $this->decode()['error']);

        foreach ([['avatar' => 'dragon'], ['displayName' => str_repeat('é', 41)], ['displayName' => "Léa\u{0007}"]] as $payload) {
            $this->put($server, $payload);
            self::assertSame(422, $this->client->getResponse()->getStatusCode(), (string) json_encode($payload));
        }
    }

    public function testAHandleUsedByAnotherAccountIsRefused(): void
    {
        $bob = $this->signedIn('bob@example.com');
        $this->put($bob, ['handle' => 'lea_echecs']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        $alice = $this->signedIn('alice@example.com');
        $this->put($alice, ['displayName' => 'Alice', 'handle' => 'LEA_ECHECS']);
        self::assertSame(409, $this->client->getResponse()->getStatusCode());
        self::assertSame('handle_taken', $this->decode()['error']);

        // Saving again with one's own handle is fine.
        $this->put($bob, ['displayName' => 'Bob', 'handle' => 'lea_echecs']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testTheDatabaseRefusesTwoAccountsWithTheSameHandle(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        foreach (['a@example.com', 'b@example.com'] as $email) {
            $user = new User();
            $user->setEmail($email)->setHandle('same');
            $entityManager->persist($user);
        }

        $this->expectException(UniqueConstraintViolationException::class);
        $entityManager->flush();
    }

    public function testTheAvailabilityOfAHandle(): void
    {
        $bob = $this->signedIn('bob@example.com');
        $this->put($bob, ['handle' => 'bob']);
        $alice = $this->signedIn('alice@example.com');

        foreach ([
            'Lea ' => ['lea', true, null],
            'BOB' => ['bob', false, 'taken'],
            'ab' => ['ab', false, 'invalid'],
            'support' => ['support', false, 'reserved'],
        ] as $handle => [$normalized, $available, $reason]) {
            $this->client->request('GET', '/api/profile/handle-availability', ['handle' => $handle], server: $alice);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertSame(['handle' => $normalized, 'available' => $available, 'reason' => $reason], $this->decode(), $handle);
        }

        // One's own handle is available to oneself.
        $this->client->request('GET', '/api/profile/handle-availability', ['handle' => 'bob'], server: $bob);
        self::assertTrue($this->decode()['available']);
    }

    public function testTheEndpointsNeedASignedInUser(): void
    {
        $this->client->request('PUT', '/api/profile/info', server: ['CONTENT_TYPE' => 'application/json'], content: '{"handle":"lea"}');
        self::assertResponseStatusCodeSame(401);

        $this->client->request('GET', '/api/profile/handle-availability', ['handle' => 'lea']);
        self::assertResponseStatusCodeSame(401);
    }

    /**
     * @param array<string, string> $server
     * @param array<string, string> $payload
     */
    private function put(array $server, array $payload): void
    {
        $this->client->request('PUT', '/api/profile/info', server: $server, content: json_encode($payload, \JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private function signedIn(string $email): array
    {
        $user = new User();
        $user->setEmail($email);
        $user->markEmailVerified();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        return ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        $data = json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        /** @var array<string, mixed> $data */
        return $data;
    }
}
