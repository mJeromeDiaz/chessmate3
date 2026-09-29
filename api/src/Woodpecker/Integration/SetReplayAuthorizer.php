<?php

declare(strict_types=1);

namespace App\Woodpecker\Integration;

use App\Entity\Puzzle\Puzzle;
use App\Entity\User;
use App\Puzzle\Attempt\ReplayAuthorizerInterface;
use App\Repository\Woodpecker\SetPuzzleRepository;

/**
 * A puzzle of one of the user's sets (e.g. a stubborn one) can be replayed freely, unrated, with
 * the Phase 2 replay: it counts neither in the rating nor in the set's statistics.
 */
final class SetReplayAuthorizer implements ReplayAuthorizerInterface
{
    public function __construct(private readonly SetPuzzleRepository $setPuzzles)
    {
    }

    public function canReplay(User $user, Puzzle $puzzle): bool
    {
        return $this->setPuzzles->isInAnySetOf($user, $puzzle);
    }
}
