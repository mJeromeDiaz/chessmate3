<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

final readonly class CreatedMfaChallenge
{
    public function __construct(
        public string $pendingToken,
        public string $method,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
