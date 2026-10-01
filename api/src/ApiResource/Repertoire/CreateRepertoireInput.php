<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use App\Entity\Repertoire\Repertoire;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateRepertoireInput
{
    #[Assert\NotBlank(normalizer: 'trim')]
    #[Assert\Length(max: Repertoire::NAME_MAX_LENGTH)]
    public string $name = '';

    #[Assert\Choice(choices: ['white', 'black'])]
    public string $color = 'white';
}
