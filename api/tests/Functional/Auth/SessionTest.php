<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\AuditLogEntry;
use App\Enum\AuditEventType;

/** The active sessions of the profile: GET and DELETE /api/auth/sessions. */
final class SessionTest extends AuthWebTestCase
{
    private const string PASSWORD = 'CorrectHorseBatteryStaple9!';
    private const string IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_6 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.6 Mobile/15E148 Safari/604.1';
    private const string MAC = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';

    public function testEachSignInIsASessionTheAskingOneFirst(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->signInFrom(self::IPHONE, '203.0.113.42');
        $accessToken = $this->signInFrom(self::MAC, '198.51.100.7');

        $sessions = $this->sessions($accessToken);

        self::assertCount(2, $sessions);
        self::assertTrue($sessions[0]['current']);
        self::assertSame(['Chrome', 'macOS', 'desktop', '198.51.100.0'], [$sessions[0]['browser'], $sessions[0]['os'], $sessions[0]['form'], $sessions[0]['ip']]);
        self::assertFalse($sessions[1]['current']);
        self::assertSame(['Safari', 'iOS', 'phone', '203.0.113.0'], [$sessions[1]['browser'], $sessions[1]['os'], $sessions[1]['form'], $sessions[1]['ip']]);
        self::assertIsString($sessions[1]['signedInAt']);
        self::assertIsString($sessions[1]['lastActiveAt']);
    }

    public function testARefreshKeepsTheSessionAndItsSignInDate(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->signInFrom(self::MAC, '198.51.100.7');
        $before = $this->sessions($this->accessTokenFromLastResponse());

        $this->client->setServerParameter('REMOTE_ADDR', '192.0.2.9');
        $this->refresh();
        self::assertResponseIsSuccessful();
        $after = $this->sessions($this->accessTokenFromLastResponse());

        self::assertCount(1, $after);
        self::assertSame($before[0]['id'], $after[0]['id']);
        self::assertSame($before[0]['signedInAt'], $after[0]['signedInAt']);
        // The last request's network.
        self::assertSame('192.0.2.0', $after[0]['ip']);
    }

    public function testAnotherSessionIsClosedAtOnce(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $phoneCookie = $this->loginFrom(self::IPHONE);
        $phoneAccessToken = $this->accessTokenFromLastResponse();
        $accessToken = $this->signInFrom(self::MAC, '198.51.100.7');
        $phone = $this->sessions($accessToken)[1];

        $this->client->request('DELETE', '/api/auth/sessions/'.$phone['id'], server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);
        self::assertResponseIsSuccessful();

        // Its refresh token and its access token stop working.
        self::assertSame(401, $this->statusWithAccessToken($phoneAccessToken));
        $this->setRefreshCookie($phoneCookie);
        self::assertSame(401, $this->refresh()->getStatusCode());

        $entries = $this->entityManager->getRepository(AuditLogEntry::class)->findBy(['eventType' => AuditEventType::SessionRevoked]);
        self::assertCount(1, $entries);
    }

    public function testTheAskingSessionAndOtherUsersSessionsCannotBeClosed(): void
    {
        $this->createVerifiedUser('bob@example.com', self::PASSWORD);
        $this->signInFrom(self::IPHONE, '203.0.113.42', 'bob@example.com');
        $bobSession = $this->sessions($this->accessTokenFromLastResponse())[0]['id'];

        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->signInFrom(self::MAC, '198.51.100.7');
        $own = $this->sessions($accessToken)[0]['id'];

        $this->client->request('DELETE', '/api/auth/sessions/'.$own, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);
        self::assertResponseStatusCodeSame(409);

        foreach ([$bobSession, '01928c3a-0000-7000-8000-000000000000', 'not-a-uuid'] as $id) {
            $this->client->request('DELETE', '/api/auth/sessions/'.$id, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);
            self::assertResponseStatusCodeSame(404);
        }
    }

    public function testTheSessionsNeedAnAccessToken(): void
    {
        $this->client->request('GET', '/api/auth/sessions');

        self::assertResponseStatusCodeSame(401);
    }

    /**
     * Signs in with this User-Agent from this IP; the client keeps the new refresh cookie.
     *
     * @return string the access token
     */
    private function signInFrom(string $userAgent, string $ip, string $email = 'alice@example.com'): string
    {
        $this->client->setServerParameter('REMOTE_ADDR', $ip);
        $this->loginFrom($userAgent, $email);

        return $this->accessTokenFromLastResponse();
    }

    /** @return string the refresh cookie of the new session */
    private function loginFrom(string $userAgent, string $email = 'alice@example.com'): string
    {
        $this->client->setServerParameter('HTTP_USER_AGENT', $userAgent);

        return $this->loginAndGetRefreshCookie($email, self::PASSWORD);
    }

    private function accessTokenFromLastResponse(): string
    {
        $accessToken = $this->decodeJson($this->client->getResponse())['accessToken'] ?? null;
        self::assertIsString($accessToken);

        return $accessToken;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function sessions(string $accessToken): array
    {
        $this->client->request('GET', '/api/auth/sessions', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);
        self::assertResponseIsSuccessful();
        $sessions = $this->decodeJson($this->client->getResponse())['sessions'] ?? null;
        self::assertIsArray($sessions);

        /** @var list<array<string, mixed>> $sessions */
        return $sessions;
    }
}
