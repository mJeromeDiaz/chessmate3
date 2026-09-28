<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final class MfaVerifyRequest
{
    #[Assert\NotBlank]
    public string $pendingToken = '';

    #[Assert\NotBlank]
    #[Assert\Regex(pattern: '/^\d{6}$/', message: 'The code must be 6 digits.')]
    public string $code = '';

    public bool $trustDevice = false;
}
