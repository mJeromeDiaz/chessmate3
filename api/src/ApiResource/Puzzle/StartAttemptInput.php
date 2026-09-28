<?php

declare(strict_types=1);

namespace App\ApiResource\Puzzle;

use App\Enum\Puzzle\Difficulty;
use Symfony\Component\Validator\Constraints as Assert;

final class StartAttemptInput
{
    /** @var list<string> theme keys; a puzzle matches if it has at least one */
    #[Assert\Count(max: 10)]
    #[Assert\All([new Assert\Type('string'), new Assert\Length(max: 32)])]
    public array $themes = [];

    public Difficulty $difficulty = Difficulty::Normal;

    /** Lichess id of a puzzle from the user's history: starts an unrated replay instead. */
    #[Assert\Regex('/^[A-Za-z0-9]{5}$/')]
    public ?string $replayOf = null;
}
