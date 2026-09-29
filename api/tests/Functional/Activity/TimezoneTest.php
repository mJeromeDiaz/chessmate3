<?php

declare(strict_types=1);

namespace App\Tests\Functional\Activity;

use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class TimezoneTest extends WebTestCase
{
    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    public function testEverythingRunsInUtc(): void
    {
        self::assertSame('UTC', date_default_timezone_get());
        self::assertSame('+00:00', self::getContainer()->get(Connection::class)->fetchOne('SELECT @@session.time_zone'));
    }

    public function testRegistrationStoresTheBrowserTimezone(): void
    {
        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'paris@example.com',
            'password' => 'a-strong-passw0rd!-for-tests',
            'timezone' => 'Europe/Paris',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(202, $this->client->getResponse()->getStatusCode());
        self::assertSame('Europe/Paris', self::getContainer()->get(UserRepository::class)->findOneByEmail('paris@example.com')?->getTimezone());
    }

    public function testAnInvalidTimezoneIsRefusedAtRegistration(): void
    {
        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'nowhere@example.com',
            'password' => 'a-strong-passw0rd!-for-tests',
            'timezone' => 'Mars/Olympus',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testTheTimezoneCanBeSetFromTheProfile(): void
    {
        $user = new User();
        $user->setEmail('alice@example.com');
        $user->markEmailVerified();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist($user);
        $entityManager->flush();
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $server = ['HTTP_AUTHORIZATION' => 'Bearer '.$token, 'CONTENT_TYPE' => 'application/json'];

        $this->client->request('GET', '/api/profile', server: $server);
        self::assertNull($this->decode()['timezone']);

        $this->client->request('PUT', '/api/profile/timezone', server: $server, content: '{"timezone":"America/New_York"}');
        self::assertSame(200, $this->client->getResponse()->getStatusCode());
        self::assertSame('America/New_York', $this->decode()['timezone']);

        $this->client->request('PUT', '/api/profile/timezone', server: $server, content: '{"timezone":"Europe/Atlantis"}');
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
    }

    public function testTheEntityRejectsANonIanaTimezone(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new User())->setTimezone('CEST');
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(): array
    {
        /** @var array<string, mixed> */
        return json_decode((string) $this->client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
    }
}
