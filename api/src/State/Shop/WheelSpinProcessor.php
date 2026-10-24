<?php

declare(strict_types=1);

namespace App\State\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Shop\WheelSpin;
use App\ApiResource\Shop\WheelSpinInput;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Shop\Wheel\WheelRefusal;
use App\Shop\Wheel\WheelSpinner;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /shop/wheel/spins (docs/SHOP.md, § 4 ter), within the store's write budget.
 *
 * @implements ProcessorInterface<WheelSpinInput, WheelSpin>
 */
final class WheelSpinProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly WheelSpinner $spinner,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $shopWriteLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): WheelSpin
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->shopWriteLimiter, $user->getUserIdentifier());
        try {
            return WheelSpin::of($this->spinner->spin($user, null === $data->dailyRewardId ? null : Uuid::fromString($data->dailyRewardId)));
        } catch (WheelRefusal $refusal) {
            throw new ConflictHttpException($refusal->reason);
        }
    }
}
