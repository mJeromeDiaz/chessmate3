<?php

declare(strict_types=1);

namespace App\State\Onboarding;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Onboarding\Onboarding;
use App\ApiResource\Onboarding\OnboardingInput;
use App\Enum\Onboarding\Goal;
use App\Enum\Onboarding\Level;
use App\Onboarding\Answer\Answers;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * PUT /onboarding: replaces the answers (validated as input), within the profile's write budget; a
 * level seeds the puzzle rating when it still can.
 *
 * @implements ProcessorInterface<OnboardingInput, Onboarding>
 */
final class OnboardingProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Answers $answers,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $profileWriteLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Onboarding
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->profileWriteLimiter, $user->getUserIdentifier());
        $this->answers->save($user, null === $data->level ? null : Level::from($data->level), null === $data->goal ? null : Goal::from($data->goal));

        return Onboarding::of($this->answers->view($user));
    }
}
