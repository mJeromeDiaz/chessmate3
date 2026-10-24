<?php

declare(strict_types=1);

namespace App\Enum\Shop;

/**
 * A prize of the gift wheel (docs/SHOP.md, § 4 ter), stored: add cases, never rename one. The
 * wheel shows each on one segment or more; the server draws the prize, the front lands on one
 * of its segments.
 */
enum WheelPrize: string
{
    case Crystals5 = 'c5';
    case Crystals30 = 'c30';
    case Crystals300 = 'c300';
    /** The 3 chests: a {@see WheelGift}. */
    case Gift = 'gift';
    /** The skull: nothing. */
    case Lose = 'lose';

    public function crowns(): int
    {
        return match ($this) {
            self::Crystals5 => 5,
            self::Crystals30 => 30,
            self::Crystals300 => 300,
            self::Gift, self::Lose => 0,
        };
    }
}
