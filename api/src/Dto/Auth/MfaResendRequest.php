<?php

declare(strict_types=1);

namespace App\Dto\Auth;

use Symfony\Component\Validator\Constraints as Assert;

final class MfaResendRequest
{
    #[Assert\NotBlank]
    public string $pendingToken = '';
}
