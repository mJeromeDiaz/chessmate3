<?php

declare(strict_types=1);

namespace App\ApiResource\Arena;

use App\Arena\ArenaRules;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A game to start: the boss, the player's side (white, black or random), the time control.
 */
final class StartGameInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 32)]
    public string $boss = '';

    #[Assert\Choice(choices: ['white', 'black', 'random'])]
    public string $color = 'random';

    #[Assert\Choice(callback: [ArenaRules::class, 'cadences'])]
    public string $cadence = ArenaRules::DEFAULT_CADENCE;
}
