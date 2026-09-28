<?php

declare(strict_types=1);

namespace App\Dto\Profile;

use Symfony\Component\Validator\Constraints as Assert;

final class AddPasswordRequest
{
    /**
     * Required only when the account has no verified email (e.g. a Lichess signup): password
     * sign-in needs an address, both as identifier and to receive the 2FA code. Ignored otherwise.
     */
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public ?string $email = null;

    #[Assert\NotBlank]
    #[Assert\Length(min: 12, max: 4096)]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM)]
    #[Assert\NotCompromisedPassword]
    public string $password = '';
}
