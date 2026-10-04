<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\CollectionOperationInterface;
use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Training\Plan;
use App\Repository\Training\PlanRepository;
use App\Security\AuthenticatedUser;
use Psr\Clock\ClockInterface;

/**
 * The user's saved sessions, or one of them (404 when another user's).
 *
 * @implements ProviderInterface<Plan>
 */
final class PlanProvider implements ProviderInterface
{
    use PlanIdTrait;

    public function __construct(
        private readonly PlanRepository $plans,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly ClockInterface $clock,
    ) {
    }

    /**
     * @return Plan|list<Plan>|null
     */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Plan|array|null
    {
        $user = $this->authenticatedUser->get();
        $now = $this->clock->now();
        if ($operation instanceof CollectionOperationInterface) {
            return array_map(static fn ($plan): Plan => Plan::from($plan, $now), $this->plans->findByUser($user));
        }
        $plan = $this->plans->findOwned(self::planId($uriVariables), $user);

        return null === $plan ? null : Plan::from($plan, $now);
    }
}
