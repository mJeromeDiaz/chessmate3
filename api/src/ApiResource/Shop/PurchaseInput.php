<?php

declare(strict_types=1);

namespace App\ApiResource\Shop;

use App\Shop\Purchase\Purchaser;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An offer bought, and how many times.
 */
final class PurchaseInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    public string $offerId = '';

    #[Assert\Range(min: 1, max: Purchaser::MAX_QUANTITY)]
    public int $quantity = 1;
}
