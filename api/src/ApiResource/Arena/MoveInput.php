<?php

declare(strict_types=1);

namespace App\ApiResource\Arena;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * A move of the side to move: its number in the game (0 for the first) and its UCI.
 */
final class MoveInput
{
    #[Assert\PositiveOrZero]
    public int $ply = 0;

    #[Assert\Regex('/^[a-h][1-8][a-h][1-8][qrbn]?$/')]
    public string $move = '';
}
