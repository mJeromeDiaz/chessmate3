<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\User;
use App\Security\TwoFactor\MfaFailureLimiter;

/**
 * Someone who has the password can't keep guessing codes through new logins and resends: an
 * account accepts at most 20 wrong codes per 24 hours.
 */
final class MfaFailureCapTest extends AuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';

    public function testWrongCodesAcrossLoginsAddUpUntilPasswordLoginIsRefused(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);

        for ($login = 0; $login < 4; ++$login) {
            $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
            $wrong = $this->wrongCodeFor($this->extractMfaCode());

            for ($attempt = 0; $attempt < 5; ++$attempt) {
                self::assertSame(401, $this->submitMfaCode($pendingToken, $wrong)->getStatusCode());
            }
        }

        $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => self::PASSWORD]);

        self::assertSame(429, $this->client->getResponse()->getStatusCode());
        self::assertEmailCount(0);
    }

    public function testOnceTheCapIsReachedEvenTheRightCodeIsRefused(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $code = $this->extractMfaCode();
        $this->exhaust($user);

        self::assertSame(401, $this->submitMfaCode($pendingToken, $code)->getStatusCode());
    }

    public function testATrustedDeviceStillSignsIn(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD, trustDevice: true);
        $this->exhaust($user);

        $response = $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => self::PASSWORD]);

        self::assertSame(200, $response->getStatusCode());
        self::assertIsString($this->decodeJson($response)['accessToken'] ?? null);
    }

    public function testAPasswordResetClearsTheCount(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->exhaust($user);

        $token = $this->requestResetToken('alice@example.com');
        $this->postJson('/api/auth/reset-password', ['token' => $token, 'newPassword' => 'AnotherStrongPassphrase77!']);
        self::assertSame(200, $this->client->getResponse()->getStatusCode());

        self::assertSame(200, $this->statusWithAccessToken($this->loginAndGetAccessToken('alice@example.com', 'AnotherStrongPassphrase77!')));
    }

    public function testTheCapIsPerAccount(): void
    {
        $this->exhaust($this->createVerifiedUser('mallory@example.com', self::PASSWORD));
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);

        self::assertSame(200, $this->statusWithAccessToken($this->loginAndGetAccessToken('alice@example.com', self::PASSWORD)));
    }

    private function exhaust(User $user): void
    {
        $limiter = self::getContainer()->get(MfaFailureLimiter::class);
        self::assertInstanceOf(MfaFailureLimiter::class, $limiter);

        for ($i = 0; $i < 20; ++$i) {
            $limiter->recordFailure($user);
        }
    }
}
