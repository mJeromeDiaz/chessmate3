<?php

declare(strict_types=1);

namespace App\Security\RefreshToken;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the HttpOnly refresh-token cookie.
 *
 * Scoped to "/api/auth" (not just the refresh endpoint) because logout also needs to read and
 * clear it — still far narrower than sending it on every API request.
 */
final readonly class RefreshTokenCookieFactory
{
    private const COOKIE_NAME = 'refresh_token';
    private const PATH = '/api/auth';

    public function __construct(
        #[Autowire('%env(bool:REFRESH_COOKIE_SECURE)%')]
        private bool $secure,
    ) {
    }

    public function create(IssuedRefreshToken $token): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue($token->plainToken)
            ->withExpires($token->expiresAt)
            ->withPath(self::PATH)
            ->withSecure($this->secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    public function clear(): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue(null)
            ->withExpires(new \DateTimeImmutable('-1 year'))
            ->withPath(self::PATH)
            ->withSecure($this->secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_STRICT);
    }

    public function readFrom(Request $request): ?string
    {
        return $request->cookies->get(self::COOKIE_NAME);
    }
}
