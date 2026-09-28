<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\MfaChallenge;
use App\Enum\AuditEventType;
use App\Repository\AuditLogEntryRepository;

final class MfaVerifyControllerTest extends AuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';

    public function testValidCodeIssuesAccessTokenAndRefreshCookie(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);

        $response = $this->submitMfaCode($pendingToken, $this->extractMfaCode());

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($this->decodeJson($response)['accessToken'] ?? null);

        $cookies = $response->headers->getCookies();
        self::assertCount(1, $cookies, 'Only the refresh cookie is set when the device is not trusted.');
        self::assertSame('refresh_token', $cookies[0]->getName());
        self::assertTrue($cookies[0]->isHttpOnly());
        self::assertSame('strict', $cookies[0]->getSameSite());
    }

    public function testAccessTokenFromVerifiedLoginGrantsAccessToProtectedRoutes(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        $this->client->request('GET', '/api/profile/trusted-devices', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);

        self::assertSame(200, $this->client->getResponse()->getStatusCode());
    }

    public function testWrongCodeIsRejectedAndIssuesNothing(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);

        $response = $this->submitMfaCode($pendingToken, $this->wrongCodeFor($this->extractMfaCode()));

        self::assertSame(401, $response->getStatusCode());
        self::assertArrayNotHasKey('accessToken', $this->decodeJson($response));
        self::assertSame([], $response->headers->getCookies());
    }

    public function testBruteForceLocksTheChallengeAfterFiveAttemptsEvenForTheRightCode(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $code = $this->extractMfaCode();

        for ($i = 0; $i < MfaChallenge::MAX_ATTEMPTS; ++$i) {
            self::assertSame(401, $this->submitMfaCode($pendingToken, $this->wrongCodeFor($code))->getStatusCode());
        }

        // The correct code is now useless: code *and* pending token are invalidated, the user must
        // restart from the password step.
        self::assertSame(401, $this->submitMfaCode($pendingToken, $code)->getStatusCode());

        $challenge = self::getContainer()->get('doctrine')->getRepository(MfaChallenge::class)->findOneBy([]);
        self::assertInstanceOf(MfaChallenge::class, $challenge);
        self::assertNotNull($challenge->getLockedAt());
        self::assertSame(MfaChallenge::MAX_ATTEMPTS, $challenge->getAttempts());

        $lockouts = self::getContainer()->get(AuditLogEntryRepository::class)->findBy([
            'user' => $user,
            'eventType' => AuditEventType::MfaPendingExpired,
        ]);
        self::assertCount(1, $lockouts);
    }

    public function testExpiredCodeIsRejected(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $code = $this->extractMfaCode();

        $this->setOnAllRows(MfaChallenge::class, 'expiresAt', new \DateTimeImmutable('-1 second'));

        self::assertSame(401, $this->submitMfaCode($pendingToken, $code)->getStatusCode());
    }

    public function testCodeCannotBeReused(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $code = $this->extractMfaCode();

        self::assertSame(200, $this->submitMfaCode($pendingToken, $code)->getStatusCode());
        self::assertSame(401, $this->submitMfaCode($pendingToken, $code)->getStatusCode());
    }

    public function testCodeIsBoundToThePendingTokenOfTheLoginThatGeneratedIt(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->createVerifiedUser('mallory@example.com', self::PASSWORD);

        $alicePendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $this->startLogin('mallory@example.com', self::PASSWORD);
        $malloryCode = $this->extractMfaCode();

        // Mallory's own valid code, presented with Alice's pending token.
        self::assertSame(401, $this->submitMfaCode($alicePendingToken, $malloryCode)->getStatusCode());
    }

    public function testStartingANewLoginInvalidatesThePreviousPendingChallenge(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);

        $firstPendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $firstCode = $this->extractMfaCode();
        $secondPendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $secondCode = $this->extractMfaCode();

        self::assertSame(401, $this->submitMfaCode($firstPendingToken, $firstCode)->getStatusCode());
        self::assertSame(200, $this->submitMfaCode($secondPendingToken, $secondCode)->getStatusCode());
    }

    public function testUnknownPendingTokenIsRejected(): void
    {
        self::assertSame(401, $this->submitMfaCode(bin2hex(random_bytes(32)), '123456')->getStatusCode());
    }

    public function testMalformedCodeIsRejectedByValidation(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);

        self::assertSame(422, $this->submitMfaCode($pendingToken, '12345a')->getStatusCode());
    }

    public function testVerifyIsRateLimitedPerIp(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->submitMfaCode(bin2hex(random_bytes(32)), '123456');
        }

        self::assertSame(429, $this->submitMfaCode(bin2hex(random_bytes(32)), '123456')->getStatusCode());
    }
}
