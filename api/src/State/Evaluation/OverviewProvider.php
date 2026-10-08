<?php

declare(strict_types=1);

namespace App\State\Evaluation;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Evaluation\Overview;
use App\Evaluation\EvaluationRules;
use App\Repository\Evaluation\AttemptRepository;
use App\Repository\Evaluation\PositionRepository;
use App\Security\AuthenticatedUser;
use App\Training\Run\TimeboxRunner;

/**
 * GET /evaluation: the current user's only. A run left behind is closed first (lazy closing), so
 * that its positions count.
 *
 * @implements ProviderInterface<Overview>
 */
final class OverviewProvider implements ProviderInterface
{
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly PositionRepository $positions,
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Overview
    {
        $user = $this->authenticatedUser->get();
        $this->runner->closeExpired($user);

        $counts = $this->attempts->countsOf($user);
        $view = new Overview();
        $view->rules = EvaluationRules::toArray();
        $view->positions = $this->positions->activeCounts();
        $view->results = [
            'played' => $counts['exact'] + $counts['close'] + $counts['miss'] + $counts['timeout'],
            ...$counts,
        ];

        return $view;
    }
}
