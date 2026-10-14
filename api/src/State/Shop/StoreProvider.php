<?php

declare(strict_types=1);

namespace App\State\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Shop\Store;
use App\Security\AuthenticatedUser;
use App\Shop\Catalog\StoreView;

/**
 * GET /shop: the store as the signed-in player sees it.
 *
 * @implements ProviderInterface<Store>
 */
final class StoreProvider implements ProviderInterface
{
    public function __construct(
        private readonly StoreView $store,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Store
    {
        return Store::of($this->store->of($this->authenticatedUser->get()));
    }
}
