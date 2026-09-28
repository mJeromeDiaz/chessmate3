<?php

declare(strict_types=1);

namespace App\Security\RefreshToken;

/**
 * The plaintext value of a freshly (re)issued refresh token, and when it expires.
 *
 * The plaintext only ever exists at issuance time: once handed to the caller it is discarded, and
 * only its hash remains in storage.
 */
final readonly class IssuedRefreshToken
{
    public function __construct(
        public string $plainToken,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
