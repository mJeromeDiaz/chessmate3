<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\AuditLogEntry;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class ResetPasswordControllerTest extends AuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';
    private const NEW_PASSWORD = 'Tr0ubadour-and-a-new-horse!';

    public function testNominalResetReplacesThePasswordWithoutSigningIn(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $token = $this->requestResetToken('alice@example.com');

        $response = $this->reset($token, self::NEW_PASSWORD);

        self::assertSame(200, $response->getStatusCode());
        self::assertArrayNotHasKey('accessToken', $this->decodeJson($response));
        self::assertTrue($this->findResponseCookie($response, 'refresh_token')->isCleared());
        self::assertTrue($this->findResponseCookie($response, 'trusted_device')->isCleared());

        $reloaded = $this->reloadUser($user);
        self::assertTrue($this->passwordHasher->isPasswordValid($reloaded, self::NEW_PASSWORD));
        self::assertFalse($this->passwordHasher->isPasswordValid($reloaded, self::PASSWORD));

        // Password-changed notification.
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertStringContainsString('modifié', (string) $email->getTextBody());

        self::assertSame(1, $this->entityManager->getRepository(AuditLogEntry::class)->count(['eventType' => AuditEventType::PasswordResetCompleted]));

        // Signing in again still goes through 2FA.
        $this->startLogin('alice@example.com', self::NEW_PASSWORD);
    }

    public function testResetRevokesSessionsAccessTokensAndTrustedDevices(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD, trustDevice: true);
        $refreshToken = $this->extractCookieValue($this->client->getResponse(), 'refresh_token');
        $trustedCookie = $this->client->getCookieJar()->get('trusted_device', '/api/auth/login', 'localhost');
        self::assertNotNull($trustedCookie);

        $this->reset($this->requestResetToken('alice@example.com'), self::NEW_PASSWORD);

        self::assertSame(401, $this->statusWithAccessToken($accessToken));

        $this->setRefreshCookie($refreshToken);
        self::assertSame(401, $this->refresh()->getStatusCode());

        $this->client->getCookieJar()->set($trustedCookie);
        $this->startLogin('alice@example.com', self::NEW_PASSWORD);
        self::assertEmailCount(1);
    }

    public function testLoginWaitingForItsCodeIsCancelled(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $code = $this->extractMfaCode();

        $this->reset($this->requestResetToken('alice@example.com'), self::NEW_PASSWORD);

        self::assertSame(401, $this->submitMfaCode($pendingToken, $code)->getStatusCode());
    }

    public function testLinkIsSingleUse(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $token = $this->requestResetToken('alice@example.com');
        self::assertSame(200, $this->reset($token, self::NEW_PASSWORD)->getStatusCode());

        self::assertSame(400, $this->reset($token, 'Yet-another-passw0rd-42!')->getStatusCode());
    }

    public function testUsingOneLinkInvalidatesTheOthers(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $firstToken = $this->requestResetToken('alice@example.com');
        $this->setOnAllRows(ResetPasswordRequest::class, 'requestedAt', new \DateTimeImmutable('-6 minutes'));
        $secondToken = $this->requestResetToken('alice@example.com');
        self::assertNotSame($firstToken, $secondToken);

        self::assertSame(200, $this->reset($secondToken, self::NEW_PASSWORD)->getStatusCode());

        self::assertSame(400, $this->reset($firstToken, 'Yet-another-passw0rd-42!')->getStatusCode());
    }

    public function testExpiredLinkIsRejected(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $token = $this->requestResetToken('alice@example.com');
        $this->setOnAllRows(ResetPasswordRequest::class, 'expiresAt', new \DateTimeImmutable('-1 second'));

        self::assertSame(400, $this->reset($token, self::NEW_PASSWORD)->getStatusCode());
        self::assertTrue($this->passwordHasher->isPasswordValid($this->reloadUser($user), self::PASSWORD));
    }

    /**
     * A right selector with a wrong verifier must fail — and must not burn the genuine link, or
     * anyone who can guess a selector could cancel someone else's reset.
     */
    public function testForgedVerifierIsRejectedWithoutConsumingTheRealLink(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $token = $this->requestResetToken('alice@example.com');
        $forged = substr($token, 0, 20).str_repeat('A', 20);

        self::assertSame(400, $this->reset($forged, self::NEW_PASSWORD)->getStatusCode());

        self::assertSame(200, $this->reset($token, self::NEW_PASSWORD)->getStatusCode());
    }

    public function testUnknownTokenIsRejected(): void
    {
        self::assertSame(400, $this->reset(str_repeat('a', 40), self::NEW_PASSWORD)->getStatusCode());
    }

    public function testMalformedTokenIsRejected(): void
    {
        self::assertSame(422, $this->reset('too-short', self::NEW_PASSWORD)->getStatusCode());
    }

    public function testWeakPasswordIsRejectedWithoutConsumingTheLink(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $token = $this->requestResetToken('alice@example.com');

        self::assertSame(422, $this->reset($token, 'short')->getStatusCode());

        self::assertSame(200, $this->reset($token, self::NEW_PASSWORD)->getStatusCode());
    }

    public function testResetVerifiesAStillUnverifiedEmail(): void
    {
        $user = $this->createUnverifiedUser('alice@example.com', self::PASSWORD);

        $this->reset($this->requestResetToken('alice@example.com'), self::NEW_PASSWORD);

        self::assertTrue($this->reloadUser($user)->isEmailVerified());
    }

    public function testRepeatedAttemptsOnTheSameSelectorAreRateLimited(): void
    {
        $selector = str_repeat('b', 20);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(400, $this->reset($selector.sprintf('%020d', $i), self::NEW_PASSWORD)->getStatusCode());
        }

        self::assertSame(429, $this->reset($selector.str_repeat('9', 20), self::NEW_PASSWORD)->getStatusCode());
    }

    private function reset(string $token, string $newPassword): Response
    {
        return $this->postJson('/api/auth/reset-password', ['token' => $token, 'newPassword' => $newPassword]);
    }

    private function reloadUser(User $user): User
    {
        $this->entityManager->clear();
        $reloaded = $this->userRepository->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }
}
