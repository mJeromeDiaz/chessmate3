<?php

declare(strict_types=1);

namespace App\Security\TrustedDevice;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Scoped to "/api/auth/login" only — the one place this cookie is ever read (to skip 2FA), and
 * where it's set (from the MFA-verify step).
 */
final readonly class TrustedDeviceCookieFactory
{
    private const COOKIE_NAME = 'trusted_device';
    private const PATH = '/api/auth/login';

    public function __construct(
        #[Autowire('%env(bool:REFRESH_COOKIE_SECURE)%')]
        private bool $secure,
    ) {
    }

    public function create(IssuedTrustedDevice $device): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withValue($device->plainToken)
            ->withExpires($device->expiresAt)
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
