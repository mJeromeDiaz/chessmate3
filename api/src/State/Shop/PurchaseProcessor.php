<?php

declare(strict_types=1);

namespace App\State\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Shop\PurchaseInput;
use App\ApiResource\Shop\Store;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Shop\Catalog\StoreView;
use App\Shop\Purchase\Purchaser;
use App\Shop\Purchase\PurchaseRefusal;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POST /shop/purchases {offerId, quantity}: 404 unknown offer, 409 not on sale or not enough
 * crowns (the reason in `detail`), within the shop's write budget.
 *
 * @implements ProcessorInterface<PurchaseInput, Store>
 */
final class PurchaseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Purchaser $purchaser,
        private readonly StoreView $store,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $shopWriteLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Store
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->shopWriteLimiter, $user->getUserIdentifier());
        try {
            $this->purchaser->buy($user, Uuid::fromString($data->offerId), $data->quantity);
        } catch (PurchaseRefusal $refusal) {
            throw PurchaseRefusal::NOT_FOUND === $refusal->reason
                ? new NotFoundHttpException($refusal->getMessage())
                : new ConflictHttpException($refusal->reason);
        }

        return Store::of($this->store->of($user));
    }
}
