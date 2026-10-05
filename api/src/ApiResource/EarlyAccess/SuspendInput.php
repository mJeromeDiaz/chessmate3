<?php

declare(strict_types=1);

namespace App\ApiResource\EarlyAccess;

use Symfony\Component\Validator\Constraints as Assert;

final class SuspendInput
{
    /** The admin's internal note, never shown to the player. */
    #[Assert\Length(max: 500)]
    public ?string $reason = null;
}
