<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The item an aid of the store (a help, a pardon) is asked on.
 */
final class HelpInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 64)]
    public string $itemId = '';
}
