<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\MfaChallenge;
use Symfony\Component\HttpFoundation\Response;

final class MfaResendControllerTest extends AuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';

    public function testResendIsRefusedRightAfterTheCodeWasSent(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);

        self::assertSame(429, $this->resend($pendingToken)->getStatusCode());
        self::assertEmailCount(0);
    }

    public function testResendSendsANewCodeAndInvalidatesThePreviousOne(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $firstCode = $this->extractMfaCode();

        $this->allowImmediateResend();
        self::assertSame(202, $this->resend($pendingToken)->getStatusCode());
        self::assertEmailCount(1);
        $secondCode = $this->extractMfaCode();

        if ($firstCode !== $secondCode) {
            self::assertSame(401, $this->submitMfaCode($pendingToken, $firstCode)->getStatusCode());
        }

        // Same pending token, new code.
        self::assertSame(200, $this->submitMfaCode($pendingToken, $secondCode)->getStatusCode());
    }

    public function testResendCannotExtendThePendingTokenPastItsMaximumLifetime(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);

        // Challenge created 25 minutes ago: a resend may only grant the ~5 minutes left of its
        // 30-minute lifetime, not a fresh 10.
        $this->setOnAllRows(MfaChallenge::class, 'createdAt', new \DateTimeImmutable('-25 minutes'));
        $this->allowImmediateResend();

        self::assertSame(202, $this->resend($pendingToken)->getStatusCode());

        $challenge = $this->findOnlyChallenge();
        self::assertLessThanOrEqual(
            $challenge->getCreatedAt()->getTimestamp() + 1800,
            $challenge->getExpiresAt()->getTimestamp(),
        );
    }

    public function testResendIsCappedPerPendingTokenPerHour(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);

        for ($i = 0; $i < 5; ++$i) {
            $this->allowImmediateResend();
            self::assertSame(202, $this->resend($pendingToken)->getStatusCode());
        }

        $this->allowImmediateResend();
        self::assertSame(429, $this->resend($pendingToken)->getStatusCode());
        self::assertEmailCount(0);
    }

    public function testResendIsRefusedOnceTheChallengeIsLocked(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $code = $this->extractMfaCode();

        for ($i = 0; $i < MfaChallenge::MAX_ATTEMPTS; ++$i) {
            $this->submitMfaCode($pendingToken, $this->wrongCodeFor($code));
        }

        $this->allowImmediateResend();
        self::assertSame(401, $this->resend($pendingToken)->getStatusCode());
        self::assertEmailCount(0);
    }

    public function testResendWithUnknownPendingTokenIsRejected(): void
    {
        self::assertSame(401, $this->resend(bin2hex(random_bytes(32)))->getStatusCode());
        self::assertEmailCount(0);
    }

    private function resend(string $pendingToken): Response
    {
        return $this->postJson('/api/auth/login/mfa/resend', ['pendingToken' => $pendingToken]);
    }

    private function allowImmediateResend(): void
    {
        $this->setOnAllRows(MfaChallenge::class, 'lastSentAt', new \DateTimeImmutable('-1 minute'));
    }

    private function findOnlyChallenge(): MfaChallenge
    {
        $challenge = $this->entityManager->getRepository(MfaChallenge::class)->findOneBy([]);
        self::assertInstanceOf(MfaChallenge::class, $challenge);

        return $challenge;
    }
}
