<?php

declare(strict_types=1);

namespace App\State\Shop;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Shop\GiftsSeenInput;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Shop\Gift\GiftNotices;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /shop/gifts/seen (docs/SHOP.md, § 4 quater), within the store's write budget.
 *
 * @implements ProcessorInterface<GiftsSeenInput, null>
 */
final class GiftsSeenProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly GiftNotices $gifts,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $shopWriteLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): null
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->shopWriteLimiter, $user->getUserIdentifier());
        $this->gifts->markSeen($user->getId(), array_map(static fn (string $id): Uuid => Uuid::fromString($id), $data->ids));

        return null;
    }
}
