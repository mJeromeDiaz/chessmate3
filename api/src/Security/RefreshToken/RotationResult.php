<?php

declare(strict_types=1);

namespace App\Security\RefreshToken;

use App\Entity\User;

final readonly class RotationResult
{
    public function __construct(
        public User $user,
        public IssuedRefreshToken $refreshToken,
    ) {
    }
}
