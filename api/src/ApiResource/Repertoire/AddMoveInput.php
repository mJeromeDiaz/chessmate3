<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use Symfony\Component\Validator\Constraints as Assert;

final class AddMoveInput extends ChangeInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $fromPositionId = '';

    #[Assert\Regex('/^[a-h][1-8][a-h][1-8][qrbn]?$/')]
    public string $uci = '';
}
