<?php

declare(strict_types=1);

namespace App\Dto\Profile;

use Symfony\Component\Validator\Constraints as Assert;

final class DeletionConfirmRequest
{
    /** The 6 digits sent by email; null for an account without a verified email (recent sign-in). */
    #[Assert\Regex('/^\d{6}$/')]
    public ?string $code = null;
}
