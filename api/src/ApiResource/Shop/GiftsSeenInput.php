<?php

declare(strict_types=1);

namespace App\ApiResource\Shop;

use App\Shop\Gift\GiftNotices;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /shop/gifts/seen: the gifts whose message was shown (`Store::$gifts`).
 */
final class GiftsSeenInput
{
    /** @var list<string> */
    #[Assert\Count(min: 1, max: GiftNotices::MAX_SEEN)]
    #[Assert\All([new Assert\Type('string'), new Assert\Uuid()])]
    public array $ids = [];
}
