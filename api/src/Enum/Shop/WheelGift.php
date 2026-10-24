<?php

declare(strict_types=1);

namespace App\Enum\Shop;

/**
 * What the gift wheel's chests hold (docs/SHOP.md, § 4 ter), stored: add cases, never rename one.
 */
enum WheelGift: string
{
    /** A 1-day streak freeze, kept with the ones bought. */
    case Freeze = 'freeze';
    /** A costume for Albert: a message only for now, nothing kept. */
    case Costume = 'costume';
}
