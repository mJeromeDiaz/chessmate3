<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * Caps wrong 2FA codes per account (config/packages/rate_limiter.yaml, "mfa_failure_account").
 *
 * The per-code cap (5 attempts) and the per-request limiters don't bound what someone holding the
 * password can try overall: every new login or resend brings a fresh code with fresh attempts.
 * This does, whatever the number of logins, resends or IPs. Once reached, password sign-in for the
 * account is refused until failures age out of the window; trusted devices and OAuth still work,
 * and a password change or reset clears the count (validated trade-off, see docs/SECURITY.md).
 */
final readonly class MfaFailureLimiter
{
    public function __construct(
        #[Autowire(service: 'limiter.mfa_failure_account')]
        private RateLimiterFactory $limiter,
    ) {
    }

    public function isBlocked(User $user): bool
    {
        return 0 === $this->limiter->create($this->key($user))->consume(0)->getRemainingTokens();
    }

    public function recordFailure(User $user): void
    {
        $this->limiter->create($this->key($user))->consume();
    }

    public function reset(User $user): void
    {
        $this->limiter->create($this->key($user))->reset();
    }

    private function key(User $user): string
    {
        return $user->getId()->toRfc4122();
    }
}
