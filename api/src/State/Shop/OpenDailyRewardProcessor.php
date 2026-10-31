<?php

declare(strict_types=1);

namespace App\State\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Shop\DailyReward;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Shop\Reward\DailyRewarder;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POST /shop/daily-rewards/{id}/open: a daily chest seen (idempotent); 404 when it is not the
 * player's. Within the shop's write budget.
 *
 * @implements ProcessorInterface<mixed, DailyReward>
 */
final class OpenDailyRewardProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly DailyRewarder $rewards,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $shopWriteLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): DailyReward
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->shopWriteLimiter, $user->getUserIdentifier());
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }
        $now = $this->clock->now();
        $opened = $this->rewards->open($user->getId(), Uuid::fromString($id), $now->setTimezone($user->getDateTimeZone())->format('Y-m-d'), $now);
        if (null === $opened) {
            throw new NotFoundHttpException('Daily reward not found.');
        }

        return DailyReward::of($opened['reward'], $opened['balance']);
    }
}
