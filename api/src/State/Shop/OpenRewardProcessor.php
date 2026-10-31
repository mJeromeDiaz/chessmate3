<?php

declare(strict_types=1);

namespace App\State\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Shop\Reward;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Shop\Reward\SessionRewarder;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POST /shop/rewards/{id}/open: the chest of a session reward seen (idempotent); 404 when it is
 * not the player's. Within the shop's write budget.
 *
 * @implements ProcessorInterface<mixed, Reward>
 */
final class OpenRewardProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly SessionRewarder $rewards,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $shopWriteLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reward
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->shopWriteLimiter, $user->getUserIdentifier());
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }
        $opened = $this->rewards->open($user->getId(), Uuid::fromString($id), $this->clock->now());
        if (null === $opened) {
            throw new NotFoundHttpException('Reward not found.');
        }

        return Reward::of($opened['reward'], $opened['balance']);
    }
}
