<?php

declare(strict_types=1);

namespace App\ApiResource\Shop;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\State\Shop\GiftsSeenProcessor;

/**
 * An admin's gift (docs/SHOP.md, § 4 quater), listed in the store (`Store::$gifts`) until its
 * message was shown; this resource only marks them seen.
 */
#[ApiResource(
    shortName: 'ShopGift',
    operations: [
        new Post(
            uriTemplate: '/shop/gifts/seen',
            status: 204,
            openapi: new Operation(summary: 'Marks an admin\'s gifts seen, their message shown (idempotent; another player\'s ignored).'),
            input: GiftsSeenInput::class,
            output: false,
            read: false,
            processor: GiftsSeenProcessor::class,
        ),
    ],
)]
final class Gift
{
}
