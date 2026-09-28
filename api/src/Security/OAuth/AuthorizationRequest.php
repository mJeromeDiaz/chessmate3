<?php

declare(strict_types=1);

namespace App\Security\OAuth;

final readonly class AuthorizationRequest
{
    public function __construct(
        public string $url,
        public string $codeVerifier,
    ) {
    }
}
