<?php

declare(strict_types=1);

namespace App\Enum\Shop;

/**
 * Where an offer shows in the store (docs/SHOP.md), in this order. Stored as its value.
 */
enum Section: string
{
    case Freeze = 'freeze';
    case PuzzleStreak = 'puzzle_streak';
    case Aids = 'aids';
    case Packs = 'packs';
    case Cosmetics = 'cosmetics';
}
