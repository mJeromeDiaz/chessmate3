<?php

declare(strict_types=1);

namespace App\Security\Password\Message;

/**
 * Someone asked for a password reset link for this email address — which may or may not belong to
 * an account; only the handler finds out.
 */
final readonly class PasswordResetRequested
{
    public function __construct(
        public string $email,
        public ?string $ip,
        public ?string $userAgent,
        public \DateTimeImmutable $requestedAt = new \DateTimeImmutable(),
        /** The language of the request (docs/I18N.md, § 5), for an account that chose none. */
        public string $locale = 'en',
    ) {
    }
}
