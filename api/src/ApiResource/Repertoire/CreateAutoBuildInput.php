<?php

declare(strict_types=1);

namespace App\ApiResource\Repertoire;

use App\Repertoire\Auto\AutoBuildService;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Settings of a repertoire from the user's games: how many of their latest games to read, and of
 * which Lichess speeds.
 */
final class CreateAutoBuildInput
{
    #[Assert\NotNull]
    #[Assert\Choice(choices: AutoBuildService::GAME_COUNTS)]
    public ?int $maxGames = null;

    /** @var list<string> */
    #[Assert\Count(min: 1, max: 4)]
    #[Assert\All([new Assert\Choice(choices: AutoBuildService::PERFS)])]
    public array $perfs = [];
}
