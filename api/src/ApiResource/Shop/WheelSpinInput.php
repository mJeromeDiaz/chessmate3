<?php

declare(strict_types=1);

namespace App\ApiResource\Shop;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST /shop/wheel/spins: the day's wheel (its daily reward), or a ticket's spin (null).
 */
final class WheelSpinInput
{
    #[Assert\Uuid]
    public ?string $dailyRewardId = null;
}
