<?php

declare(strict_types=1);

namespace App\Enum\Shop;

/**
 * The gift wheel of a day (docs/SHOP.md, § 4 ter), stored: add cases, never rename one. Null on
 * the days before the wheel (a chest of 6 to 12 crystals then).
 */
enum WheelStatus: string
{
    /** To spin, that local day only. */
    case Offered = 'offered';
    case Spun = 'spun';
    /** « Pas aujourd'hui »: lost for that day. */
    case Declined = 'declined';
    /** The player wants no wheel (the profile's setting). */
    case Off = 'off';
}
