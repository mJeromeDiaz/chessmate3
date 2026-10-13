<?php

declare(strict_types=1);

namespace App\State\PuzzleStreak;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PuzzleStreak\Overview;
use App\PuzzleStreak\StreakRules;
use App\Repository\PuzzleStreak\AttemptRepository;
use App\Repository\PuzzleStreak\StreakRepository;
use App\Security\AuthenticatedUser;
use App\Training\Run\TimeboxRunner;

/**
 * GET /puzzle-streak: the current user's only. A run left behind is closed first (lazy closing),
 * so that its streak counts.
 *
 * @implements ProviderInterface<Overview>
 */
final class OverviewProvider implements ProviderInterface
{
    public function __construct(
        private readonly StreakRepository $streaks,
        private readonly AttemptRepository $attempts,
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Overview
    {
        $user = $this->authenticatedUser->get();
        $this->runner->closeExpired($user);

        $view = new Overview();
        $view->rules = StreakRules::toArray();
        $view->records = $this->streaks->recordsOf($user);
        $view->total = $this->attempts->totalsOf($user);

        return $view;
    }
}
