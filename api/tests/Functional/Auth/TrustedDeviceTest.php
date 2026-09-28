<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\TrustedDevice;
use Symfony\Component\BrowserKit\Cookie as BrowserCookie;
use Symfony\Component\HttpFoundation\Response;

final class TrustedDeviceTest extends AuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';

    public function testTrustingTheDeviceSetsAHardenedCookieScopedToLogin(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);

        $response = $this->submitMfaCode($pendingToken, $this->extractMfaCode(), trustDevice: true);

        self::assertSame(200, $response->getStatusCode());
        $cookie = $this->findResponseCookie($response, 'trusted_device');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('strict', $cookie->getSameSite());
        self::assertSame('/api/auth/login', $cookie->getPath());
        self::assertSame(64, \strlen((string) $cookie->getValue()));
        self::assertEqualsWithDelta((new \DateTimeImmutable('+30 days'))->getTimestamp(), $cookie->getExpiresTime(), 60);

        // Only the hash is stored.
        $device = $this->entityManager->getRepository(TrustedDevice::class)->findOneBy([]);
        self::assertInstanceOf(TrustedDevice::class, $device);
        self::assertSame(hash('sha256', (string) $cookie->getValue()), $device->getTokenHash());
    }

    public function testTrustedDeviceSkipsTheCodeOnTheNextLogin(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD, trustDevice: true);

        $response = $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => self::PASSWORD]);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($this->decodeJson($response)['accessToken'] ?? null);
        $this->findResponseCookie($response, 'refresh_token');
        self::assertEmailCount(0);
    }

    public function testTrustedDeviceNeverReplacesThePassword(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD, trustDevice: true);

        $response = $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => 'WrongPassword123!']);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testAnotherUsersTrustedDeviceCookieDoesNotBypass2fa(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->createVerifiedUser('mallory@example.com', self::PASSWORD);
        // Mallory trusts her own browser, then logs in to Alice's account (password known) from it.
        $this->loginAndGetAccessToken('mallory@example.com', self::PASSWORD, trustDevice: true);

        $response = $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => self::PASSWORD]);

        self::assertSame(202, $response->getStatusCode());
        self::assertArrayHasKey('mfaPendingToken', $this->decodeJson($response));
    }

    public function testForgedTrustedDeviceCookieDoesNotBypass2fa(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->client->getCookieJar()->set(new BrowserCookie('trusted_device', bin2hex(random_bytes(32)), null, '/api/auth/login', 'localhost'));

        $response = $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => self::PASSWORD]);

        self::assertSame(202, $response->getStatusCode());
    }

    public function testExpiredTrustedDeviceNoLongerBypasses2fa(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD, trustDevice: true);

        $this->setOnAllRows(TrustedDevice::class, 'expiresAt', new \DateTimeImmutable('-1 second'));

        $response = $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => self::PASSWORD]);

        self::assertSame(202, $response->getStatusCode());
    }

    public function testListShowsActiveDevicesWithoutAnySecret(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD, trustDevice: true);

        $response = $this->listDevices($accessToken);

        self::assertSame(200, $response->getStatusCode());
        $devices = $this->decodeJson($response)['devices'] ?? null;
        self::assertIsArray($devices);
        self::assertCount(1, $devices);
        self::assertIsArray($devices[0]);
        self::assertSame(['id', 'label', 'createdAt', 'lastUsedAt', 'expiresAt'], array_keys($devices[0]));
    }

    public function testListRequiresAuthentication(): void
    {
        $this->client->request('GET', '/api/profile/trusted-devices', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testRevokedDeviceIsRemovedFromTheListAndNoLongerBypasses2fa(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD, trustDevice: true);
        $deviceId = $this->firstDeviceId($accessToken);

        self::assertSame(200, $this->revokeDevice($accessToken, $deviceId)->getStatusCode());
        self::assertSame([], $this->decodeJson($this->listDevices($accessToken))['devices'] ?? null);

        $response = $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => self::PASSWORD]);
        self::assertSame(202, $response->getStatusCode());
    }

    public function testCannotRevokeAnotherUsersDevice(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->createVerifiedUser('mallory@example.com', self::PASSWORD);
        $aliceToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD, trustDevice: true);
        $aliceDeviceId = $this->firstDeviceId($aliceToken);

        $this->client->getCookieJar()->clear();
        $malloryToken = $this->loginAndGetAccessToken('mallory@example.com', self::PASSWORD);

        self::assertSame(404, $this->revokeDevice($malloryToken, $aliceDeviceId)->getStatusCode());
        self::assertCount(1, (array) ($this->decodeJson($this->listDevices($aliceToken))['devices'] ?? []));
    }

    public function testRevokingAnUnknownOrMalformedIdIsNotFound(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        self::assertSame(404, $this->revokeDevice($accessToken, 'not-a-uuid')->getStatusCode());
        self::assertSame(404, $this->revokeDevice($accessToken, '0192a5b8-0000-7000-8000-000000000000')->getStatusCode());
    }

    private function listDevices(string $accessToken): Response
    {
        $this->client->request('GET', '/api/profile/trusted-devices', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        return $this->client->getResponse();
    }

    private function revokeDevice(string $accessToken, string $deviceId): Response
    {
        $this->client->request('DELETE', '/api/profile/trusted-devices/'.$deviceId, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        return $this->client->getResponse();
    }

    private function firstDeviceId(string $accessToken): string
    {
        $devices = $this->decodeJson($this->listDevices($accessToken))['devices'] ?? null;
        self::assertIsArray($devices);
        self::assertIsArray($devices[0] ?? null);
        self::assertIsString($devices[0]['id'] ?? null);

        return $devices[0]['id'];
    }
}
