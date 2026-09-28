<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use Symfony\Component\HttpFoundation\Cookie;

final readonly class StartedOAuthFlow
{
    public function __construct(
        public string $authorizationUrl,
        public Cookie $bindingCookie,
    ) {
    }
}
