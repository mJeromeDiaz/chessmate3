<?php

declare(strict_types=1);

namespace App\State\Onboarding;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Onboarding\Onboarding;
use App\Onboarding\Answer\Answers;
use App\Security\AuthenticatedUser;

/**
 * GET /onboarding: the current user's answers, the level suggested by Lichess, the puzzle rating.
 *
 * @implements ProviderInterface<Onboarding>
 */
final class OnboardingProvider implements ProviderInterface
{
    public function __construct(
        private readonly Answers $answers,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Onboarding
    {
        return Onboarding::of($this->answers->view($this->authenticatedUser->get()));
    }
}
