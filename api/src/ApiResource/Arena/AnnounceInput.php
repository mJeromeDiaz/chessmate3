<?php

declare(strict_types=1);

namespace App\ApiResource\Arena;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The bosses whose arrival the client showed.
 */
final class AnnounceInput
{
    /** @var list<string> */
    #[Assert\Count(min: 1, max: 50)]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 32)])]
    public array $bosses = [];
}
