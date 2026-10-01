<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The new prepared move (UCI) in place of the user's move of the URI.
 */
final class ReplaceMoveInput extends ChangeInput
{
    #[Assert\Regex('/^[a-h][1-8][a-h][1-8][qrbn]?$/')]
    public string $uci = '';
}
