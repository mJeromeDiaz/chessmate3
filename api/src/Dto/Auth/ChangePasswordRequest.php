<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final class ChangePasswordRequest
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 4096)]
    public string $currentPassword = '';

    #[Assert\NotBlank]
    #[Assert\Length(min: 12, max: 4096)]
    #[Assert\PasswordStrength(minScore: Assert\PasswordStrength::STRENGTH_MEDIUM)]
    #[Assert\NotCompromisedPassword]
    #[Assert\NotIdenticalTo(propertyPath: 'currentPassword', message: 'The new password must differ from the current one.')]
    public string $newPassword = '';
}
