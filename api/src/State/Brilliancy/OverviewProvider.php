<?php

declare(strict_types=1);

namespace App\State\Brilliancy;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Brilliancy\Overview;
use App\Brilliancy\BrilliancyRules;
use App\Brilliancy\Index\Indexer;
use App\Repository\Brilliancy\AttemptRepository;
use App\Security\AuthenticatedUser;
use App\Training\Run\TimeboxRunner;

/**
 * GET /brilliancy: the current user's only. A run left behind is closed first (lazy closing), so
 * that its positions count.
 *
 * @implements ProviderInterface<Overview>
 */
final class OverviewProvider implements ProviderInterface
{
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly Indexer $indexer,
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Overview
    {
        $user = $this->authenticatedUser->get();
        $this->runner->closeExpired($user);

        $view = new Overview();
        $view->rules = BrilliancyRules::toArray();
        $view->positions = $this->indexer->counts();
        $view->results = $this->attempts->countsOf($user);

        return $view;
    }
}
