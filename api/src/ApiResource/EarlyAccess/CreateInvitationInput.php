<?php

declare(strict_types=1);

namespace App\ApiResource\EarlyAccess;

use Symfony\Component\Validator\Constraints as Assert;

final class CreateInvitationInput
{
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    public string $email = '';

    /** Omitted: 7 days. Must be in the future, within a year. */
    public ?\DateTimeImmutable $expiresAt = null;

    /** The key never expires ($expiresAt is then ignored). */
    public bool $neverExpires = false;
}
