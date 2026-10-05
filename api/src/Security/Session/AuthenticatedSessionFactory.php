<?php

declare(strict_types=1);

namespace App\Security\Session;

use App\Entity\User;
use App\Security\Account\AccountSuspendedException;
use App\Security\RefreshToken\RefreshTokenCookieFactory;
use App\Security\RefreshToken\RefreshTokenService;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;

/**
 * Issues a full session (access token + a brand new refresh-token family) for a user who has
 * completed authentication — whether that took one step (a trusted device skipping 2FA) or two
 * (password then a verified email code). Callers are responsible for their own audit logging, since
 * what's worth recording differs (e.g. whether a trusted device was used).
 *
 * Every sign-in ends here, so this is where a suspended account is stopped (docs/EARLY_ACCESS.md),
 * whatever the way in: password, trusted device, email code, Google, Lichess.
 */
final readonly class AuthenticatedSessionFactory
{
    public function __construct(
        private JWTTokenManagerInterface $jwtManager,
        private RefreshTokenService $refreshTokenService,
        private RefreshTokenCookieFactory $cookieFactory,
    ) {
    }

    /**
     * @throws AccountSuspendedException
     */
    public function issueFor(User $user): IssuedSession
    {
        if ($user->isSuspended()) {
            throw new AccountSuspendedException();
        }

        $accessToken = $this->jwtManager->create($user);
        $refreshToken = $this->refreshTokenService->issueNewFamily($user);

        return new IssuedSession($accessToken, $this->cookieFactory->create($refreshToken));
    }
}
