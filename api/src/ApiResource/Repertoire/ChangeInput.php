<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The version of the repertoire the client's change is based on (optional: no check).
 */
class ChangeInput
{
    #[Assert\PositiveOrZero]
    public ?int $baseVersion = null;
}
