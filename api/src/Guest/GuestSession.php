<?php

declare(strict_types=1);

namespace App\Guest;

use App\Entity\User;
use App\Security\RefreshToken\RefreshTokenCookieFactory;
use App\Security\RefreshToken\RefreshTokenService;
use Symfony\Component\HttpFoundation\Request;

/**
 * The guest playing in this browser (docs/GUEST.md), from its refresh cookie: a sign-up started
 * here turns that guest into the account instead of opening a new one. The cookie (HttpOnly,
 * SameSite=Strict, path /api/auth) reaches the sign-up endpoints, a form posted to the OAuth
 * redirect included, where no access token can travel.
 */
final readonly class GuestSession
{
    public function __construct(
        private RefreshTokenCookieFactory $cookies,
        private RefreshTokenService $refreshTokens,
    ) {
    }

    /** The guest of this browser's session, null without one (or for a real account's session). */
    public function of(Request $request): ?User
    {
        $token = $this->cookies->readFrom($request);
        $user = \is_string($token) && '' !== $token ? $this->refreshTokens->userOf($token) : null;

        return null !== $user && $user->isGuest() ? $user : null;
    }
}
