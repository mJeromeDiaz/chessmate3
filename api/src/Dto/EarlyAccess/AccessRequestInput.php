<?php

declare(strict_types=1);

namespace App\Dto\EarlyAccess;

use Symfony\Component\Validator\Constraints as Assert;

final class AccessRequestInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';

    /** Honeypot: hidden from visitors, so only a bot fills it in. */
    public ?string $website = null;
}
