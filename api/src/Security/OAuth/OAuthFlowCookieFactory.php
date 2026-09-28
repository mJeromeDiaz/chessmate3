<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\OAuthFlow;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * The cookie binding an {@see OAuthFlow} to the browser that started it.
 *
 * SameSite=Lax, unlike every other cookie of this API: the callback is a top-level navigation
 * coming from the provider's site, on which a Strict cookie would not be sent. Lax still keeps it
 * off cross-site subrequests and POSTs, and it is scoped to the OAuth routes and lives 10 minutes.
 */
final readonly class OAuthFlowCookieFactory
{
    private const COOKIE_NAME = 'oauth_flow';
    private const PATH = '/api/auth/oauth';

    public function __construct(
        #[Autowire('%env(bool:REFRESH_COOKIE_SECURE)%')]
        private bool $secure,
    ) {
    }

    public function create(string $bindingValue, \DateTimeImmutable $expiresAt): Cookie
    {
        return $this->base()->withValue($bindingValue)->withExpires($expiresAt);
    }

    public function clear(): Cookie
    {
        return $this->base()->withValue(null)->withExpires(new \DateTimeImmutable('-1 year'));
    }

    public function readFrom(Request $request): ?string
    {
        $value = $request->cookies->get(self::COOKIE_NAME);

        return \is_string($value) && '' !== $value ? $value : null;
    }

    private function base(): Cookie
    {
        return Cookie::create(self::COOKIE_NAME)
            ->withPath(self::PATH)
            ->withSecure($this->secure)
            ->withHttpOnly(true)
            ->withSameSite(Cookie::SAMESITE_LAX);
    }
}
