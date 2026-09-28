<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\AuditLogEntry;
use App\Entity\User;
use App\Enum\AuditEventType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class ChangePasswordControllerTest extends AuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';
    private const NEW_PASSWORD = 'Tr0ubadour-and-a-new-horse!';

    public function testNominalChangeReplacesThePasswordAndKeepsTheCallerSignedIn(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        $response = $this->changePassword($accessToken, self::PASSWORD, self::NEW_PASSWORD);

        self::assertSame(200, $response->getStatusCode());
        $newAccessToken = $this->decodeJson($response)['accessToken'] ?? null;
        self::assertIsString($newAccessToken);
        $this->findResponseCookie($response, 'refresh_token');

        $reloaded = $this->reloadUser($user);
        self::assertTrue($this->passwordHasher->isPasswordValid($reloaded, self::NEW_PASSWORD));
        self::assertFalse($this->passwordHasher->isPasswordValid($reloaded, self::PASSWORD));

        // The new session works: its access token is accepted and its refresh cookie rotates.
        self::assertSame(200, $this->statusWithAccessToken($newAccessToken));
        self::assertSame(200, $this->refresh()->getStatusCode());

        self::assertSame(1, $this->countAuditEvents(AuditEventType::PasswordChanged));
    }

    public function testNotificationEmailIsQueued(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        $this->changePassword($accessToken, self::PASSWORD, self::NEW_PASSWORD);

        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('alice@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('modifié', (string) $email->getTextBody());
        self::assertStringContainsString('/#/forgot-password', (string) $email->getTextBody());
        self::assertStringNotContainsString(self::NEW_PASSWORD, (string) $email->getTextBody());
    }

    public function testOtherSessionsAndTheCallersOwnFamilyAreRevoked(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $otherSession = $this->loginAndGetRefreshCookie('alice@example.com', self::PASSWORD);
        $ownOldSession = $this->loginAndGetRefreshCookie('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        $this->changePassword($accessToken, self::PASSWORD, self::NEW_PASSWORD);

        foreach ([$otherSession, $ownOldSession] as $revoked) {
            $this->setRefreshCookie($revoked);
            self::assertSame(401, $this->refresh()->getStatusCode());
        }
    }

    public function testAccessTokensIssuedBeforeTheChangeStopWorkingImmediately(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $otherDeviceAccessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        $this->changePassword($accessToken, self::PASSWORD, self::NEW_PASSWORD);

        self::assertSame(401, $this->statusWithAccessToken($accessToken));
        self::assertSame(401, $this->statusWithAccessToken($otherDeviceAccessToken));
    }

    public function testTrustedDevicesAreRevokedAndTheCookieCleared(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD, trustDevice: true);
        $oldCookie = $this->client->getCookieJar()->get('trusted_device', '/api/auth/login', 'localhost');
        self::assertNotNull($oldCookie);

        $response = $this->changePassword($accessToken, self::PASSWORD, self::NEW_PASSWORD);

        $cleared = $this->findResponseCookie($response, 'trusted_device');
        self::assertTrue($cleared->isCleared());
        self::assertSame('/api/auth/login', $cleared->getPath());

        // Even if the browser kept the old cookie, it no longer skips the code.
        $this->client->getCookieJar()->set($oldCookie);
        $this->startLogin('alice@example.com', self::NEW_PASSWORD);
        self::assertEmailCount(1);
    }

    public function testWrongCurrentPasswordChangesNothing(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        $response = $this->changePassword($accessToken, 'NotMyPassword-123!', self::NEW_PASSWORD);

        self::assertSame(400, $response->getStatusCode());
        self::assertTrue($this->passwordHasher->isPasswordValid($this->reloadUser($user), self::PASSWORD));
        self::assertSame(200, $this->statusWithAccessToken($accessToken));
        self::assertSame(200, $this->refresh()->getStatusCode());
        self::assertQueuedEmailCount(0);
        self::assertSame(1, $this->countAuditEvents(AuditEventType::PasswordChangeFailed));
    }

    public function testCurrentPasswordBruteForceIsRateLimited(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(400, $this->changePassword($accessToken, 'Guess-number-'.$i, self::NEW_PASSWORD)->getStatusCode());
        }

        // Even the right password is refused once the limit is hit.
        self::assertSame(429, $this->changePassword($accessToken, self::PASSWORD, self::NEW_PASSWORD)->getStatusCode());
    }

    public function testRequiresAnAccessToken(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);

        $response = $this->postJson('/api/auth/password/change', [
            'currentPassword' => self::PASSWORD,
            'newPassword' => self::NEW_PASSWORD,
        ]);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testWeakNewPasswordIsRejected(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        self::assertSame(422, $this->changePassword($accessToken, self::PASSWORD, 'short')->getStatusCode());
    }

    public function testNewPasswordMustDifferFromTheCurrentOne(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        self::assertSame(422, $this->changePassword($accessToken, self::PASSWORD, self::PASSWORD)->getStatusCode());
    }

    public function testAccountWithoutPasswordIsRefused(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        // Simulates an OAuth-only account (a password is added from the profile, not here).
        $this->setOnAllRows(User::class, 'password', null);

        self::assertSame(409, $this->changePassword($accessToken, self::PASSWORD, self::NEW_PASSWORD)->getStatusCode());
        self::assertNull($this->reloadUser($user)->getPassword());
    }

    private function changePassword(string $accessToken, string $current, string $new): Response
    {
        return $this->postJson('/api/auth/password/change', [
            'currentPassword' => $current,
            'newPassword' => $new,
        ], ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);
    }

    private function reloadUser(User $user): User
    {
        $this->entityManager->clear();
        $reloaded = $this->userRepository->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }

    private function countAuditEvents(AuditEventType $type): int
    {
        return $this->entityManager->getRepository(AuditLogEntry::class)->count(['eventType' => $type]);
    }
}
