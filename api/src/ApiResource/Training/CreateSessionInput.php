<?php

declare(strict_types=1);

namespace App\ApiResource\Training;

use App\Entity\Training\Session;
use Symfony\Component\Validator\Constraints as Assert;

final class CreateSessionInput
{
    #[Assert\Length(max: 120)]
    public string $title = '';

    #[Assert\Length(max: 500)]
    public string $description = '';

    /** @var list<SessionStepInput> */
    #[Assert\Count(min: 1, max: Session::MAX_STEPS)]
    #[Assert\Valid]
    public array $steps = [];
}
