<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\RefreshToken;

final class RefreshControllerTest extends AuthWebTestCase
{
    public function testNominalRotationReturnsNewAccessTokenAndNewCookie(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');
        $oldToken = $this->loginAndGetRefreshCookie('alice@example.com', 'CorrectHorseBatteryStaple9!');

        $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_X-Refresh-Request' => '1']);

        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());

        $body = $this->decodeJson($response);
        self::assertArrayHasKey('accessToken', $body);

        $newToken = $this->extractCookieValue($response, 'refresh_token');
        self::assertNotSame($oldToken, $newToken);
    }

    public function testMissingCsrfHeaderIsRejected(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');
        $this->loginAndGetRefreshCookie('alice@example.com', 'CorrectHorseBatteryStaple9!');

        $this->client->request('POST', '/api/auth/refresh');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testNoCookieIsRejected(): void
    {
        $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_X-Refresh-Request' => '1']);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testExpiredOrGarbageTokenIsRejected(): void
    {
        $this->setRefreshCookie('not-a-real-token');

        $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_X-Refresh-Request' => '1']);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /**
     * The core attack scenario: an old, already-rotated refresh token is replayed (e.g. stolen from
     * a log or a compromised device after the legitimate client already refreshed). The whole
     * family — including the token that legitimately replaced it — must be revoked, so the
     * legitimate client is forced to log in again rather than silently keep a session an attacker
     * has also seen.
     */
    public function testReplayingAnAlreadyRotatedTokenRevokesTheWholeFamily(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');
        $firstToken = $this->loginAndGetRefreshCookie('alice@example.com', 'CorrectHorseBatteryStaple9!');

        $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_X-Refresh-Request' => '1']);
        $secondToken = $this->extractCookieValue($this->client->getResponse(), 'refresh_token');
        self::assertNotSame($firstToken, $secondToken);

        // Replay the first (already consumed) token.
        $this->setRefreshCookie($firstToken);
        $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_X-Refresh-Request' => '1']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        // The legitimate second token, which replaced it, must now be dead too.
        $this->setRefreshCookie($secondToken);
        $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_X-Refresh-Request' => '1']);
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testRepeatedRefreshAttemptsFromTheSameIpAreRateLimited(): void
    {
        for ($i = 0; $i < 61; ++$i) {
            $this->setRefreshCookie('not-a-real-token-'.$i);
            $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_X-Refresh-Request' => '1']);
        }

        self::assertSame(429, $this->client->getResponse()->getStatusCode());
    }

    public function testNewSessionHasAnIdleTimeoutShorterThanItsAbsoluteLifetime(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');
        $this->loginAndGetRefreshCookie('alice@example.com', 'CorrectHorseBatteryStaple9!');

        $cookie = $this->findResponseCookie($this->client->getResponse(), 'refresh_token');
        self::assertEqualsWithDelta((new \DateTimeImmutable('+7 days'))->getTimestamp(), $cookie->getExpiresTime(), 60);

        $token = $this->entityManager->getRepository(RefreshToken::class)->findOneBy([]);
        self::assertInstanceOf(RefreshToken::class, $token);
        self::assertEqualsWithDelta((new \DateTimeImmutable('+30 days'))->getTimestamp(), $token->getFamilyExpiresAt()->getTimestamp(), 60);
    }

    public function testRotationNeverExtendsASessionPastItsAbsoluteExpiry(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');
        $this->loginAndGetRefreshCookie('alice@example.com', 'CorrectHorseBatteryStaple9!');

        // The session is 29 days and 23 hours old: only one hour left.
        $familyExpiresAt = new \DateTimeImmutable('+1 hour');
        $this->setOnAllRows(RefreshToken::class, 'familyExpiresAt', $familyExpiresAt);

        $response = $this->refresh();

        self::assertSame(200, $response->getStatusCode());
        self::assertEqualsWithDelta($familyExpiresAt->getTimestamp(), $this->findResponseCookie($response, 'refresh_token')->getExpiresTime(), 5);
    }

    public function testSessionPastItsAbsoluteExpiryCannotBeRefreshed(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');
        $this->loginAndGetRefreshCookie('alice@example.com', 'CorrectHorseBatteryStaple9!');

        // Token itself still within its idle window, but the family is over.
        $this->setOnAllRows(RefreshToken::class, 'familyExpiresAt', new \DateTimeImmutable('-1 second'));

        self::assertSame(401, $this->refresh()->getStatusCode());
    }

    public function testIdleSessionCannotBeRefreshed(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');
        $this->loginAndGetRefreshCookie('alice@example.com', 'CorrectHorseBatteryStaple9!');

        $this->setOnAllRows(RefreshToken::class, 'valid', new \DateTime('-1 second'));

        self::assertSame(401, $this->refresh()->getStatusCode());
    }

    /**
     * After a detected reuse, an attacker may also hold an access token minted from the stolen
     * family: it must stop working right away, not after its 15 minutes.
     */
    public function testReuseDetectionAlsoInvalidatesAccessTokensAlreadyIssued(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');
        $firstToken = $this->loginAndGetRefreshCookie('alice@example.com', 'CorrectHorseBatteryStaple9!');

        $accessToken = $this->decodeJson($this->refresh())['accessToken'] ?? null;
        self::assertIsString($accessToken);
        self::assertSame(200, $this->statusWithAccessToken($accessToken));

        $this->setRefreshCookie($firstToken);
        self::assertSame(401, $this->refresh()->getStatusCode());

        self::assertSame(401, $this->statusWithAccessToken($accessToken));
    }
}
