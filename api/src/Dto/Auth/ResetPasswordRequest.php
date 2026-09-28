<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final class ResetPasswordRequest
{
    /** Selector (20 chars) + verifier (20 chars), as emailed by symfonycasts/reset-password-bundle. */
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 40)]
    public string $token = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 12, max: 4096)]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM)]
    #[Assert\NotCompromisedPassword]
    public string $newPassword = '';
}
