<?php

declare(strict_types=1);

namespace App\State\Blindfold;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Blindfold\Recall;
use App\Blindfold\Recall\Progress;
use App\Blindfold\Recall\RecallRules;
use App\Security\AuthenticatedUser;
use App\Training\Run\TimeboxRunner;

/**
 * GET /blindfold/recall: the current user's only. A run left behind is closed first (lazy closing),
 * so that its rebuilds count.
 *
 * @implements ProviderInterface<Recall>
 */
final class RecallProvider implements ProviderInterface
{
    public function __construct(
        private readonly Progress $progress,
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Recall
    {
        $user = $this->authenticatedUser->get();
        $this->runner->closeExpired($user);

        $view = new Recall();
        $view->rules = RecallRules::toArray();
        $view->levels = $this->progress->levels($user);
        $view->highest = $this->progress->highest($user);

        return $view;
    }
}
