<?php

declare(strict_types=1);

namespace App\Security\TrustedDevice;

final readonly class IssuedTrustedDevice
{
    public function __construct(
        public string $plainToken,
        public \DateTimeImmutable $expiresAt,
    ) {
    }
}
