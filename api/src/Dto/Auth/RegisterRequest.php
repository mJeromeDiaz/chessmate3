<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final class RegisterRequest
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 12, max: 4096)]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM)]
    #[Assert\NotCompromisedPassword]
    public string $password = '';

    /** IANA timezone detected by the browser (optional). */
    #[Assert\Timezone]
    public ?string $timezone = null;

    /** Early access key (docs/EARLY_ACCESS.md), checked by the registration gate. */
    public ?string $invitationKey = null;
}
