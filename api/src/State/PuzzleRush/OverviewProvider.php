<?php

declare(strict_types=1);

namespace App\State\PuzzleRush;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\PuzzleRush\Overview;
use App\PuzzleRush\Record\Records;
use App\PuzzleRush\RushRules;
use App\Security\AuthenticatedUser;
use App\Training\Run\TimeboxRunner;

/**
 * GET /puzzle-rush: the current user's only. A run left behind is closed first (lazy closing), so
 * that it counts.
 *
 * @implements ProviderInterface<Overview>
 */
final class OverviewProvider implements ProviderInterface
{
    public function __construct(
        private readonly Records $records,
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Overview
    {
        $user = $this->authenticatedUser->get();
        $this->runner->closeExpired($user);

        $view = new Overview();
        $view->rules = RushRules::toArray();
        $view->records = $this->records->of($user);
        $view->recent = $this->records->recent($user);

        return $view;
    }
}
