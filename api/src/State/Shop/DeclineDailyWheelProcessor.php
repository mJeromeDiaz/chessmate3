<?php

declare(strict_types=1);

namespace App\State\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Shop\DailyReward;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Shop\Reward\DailyRewarder;
use App\Shop\Wheel\WheelRefusal;
use App\Shop\Wheel\WheelSpinner;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POST /shop/daily-rewards/{id}/decline: « Pas aujourd'hui », the day's gift wheel lost
 * (docs/SHOP.md, § 4 ter); a badge chest of that day stays to open. 409 `no_spin`.
 *
 * @implements ProcessorInterface<mixed, DailyReward>
 */
final class DeclineDailyWheelProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly WheelSpinner $spinner,
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
        $today = $this->clock->now()->setTimezone($user->getDateTimeZone())->format('Y-m-d');
        if (null === $this->rewards->find($user->getId(), Uuid::fromString($id), $today)) {
            throw new NotFoundHttpException('Daily reward not found.');
        }
        try {
            $this->spinner->decline($user, Uuid::fromString($id));
        } catch (WheelRefusal $refusal) {
            throw new ConflictHttpException($refusal->reason);
        }

        return DailyReward::of($this->rewards->find($user->getId(), Uuid::fromString($id), $today) ?? throw new NotFoundHttpException(), $this->rewards->balance($user->getId()));
    }
}
