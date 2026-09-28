<?php

declare(strict_types=1);

namespace App\Security\Session;

use Symfony\Component\HttpFoundation\Cookie;

final readonly class IssuedSession
{
    public function __construct(
        public string $accessToken,
        public Cookie $refreshCookie,
    ) {
    }
}
