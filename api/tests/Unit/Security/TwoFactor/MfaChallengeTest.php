<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security\TwoFactor;

use App\Entity\MfaChallenge;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class MfaChallengeTest extends TestCase
{
    public function testFreshChallengeIsActive(): void
    {
        self::assertTrue($this->challenge('+10 minutes')->isActive());
    }

    public function testExpiredChallengeIsInactive(): void
    {
        self::assertFalse($this->challenge('-1 second')->isActive());
    }

    public function testRestartClearsTheCodeAndResetsAttemptsAndExpiry(): void
    {
        $challenge = $this->challenge('+1 minute');
        $challenge->setCodeHash('previous-hash');
        $newExpiry = new \DateTimeImmutable('+10 minutes');

        $challenge->restart($newExpiry);

        self::assertNull($challenge->getCodeHash());
        self::assertSame(0, $challenge->getAttempts());
        self::assertSame($newExpiry, $challenge->getExpiresAt());
    }

    public function testUserAgentIsTruncatedToFitItsColumn(): void
    {
        $challenge = new MfaChallenge(new User(), 'email', 'hash', new \DateTimeImmutable('+10 minutes'), null, str_repeat('a', 1000));

        self::assertSame(255, mb_strlen((string) $challenge->getUserAgent()));
    }

    private function challenge(string $expiresIn): MfaChallenge
    {
        return new MfaChallenge(new User(), 'email', 'hash', new \DateTimeImmutable($expiresIn), null, null);
    }
}
