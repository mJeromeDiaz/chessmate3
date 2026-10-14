<?php

declare(strict_types=1);

namespace App\Shop\Pardon;

use App\Coordinates\Pardon\PardonStockInterface;
use App\Enum\Shop\ItemType;
use App\Shop\Inventory\Inventory;
use Symfony\Component\Uid\Uuid;

/**
 * The store's pardons for coordinates series (docs/SHOP.md), spent one at a time.
 */
final readonly class CoordinatesPardons implements PardonStockInterface
{
    public function __construct(
        private Inventory $inventory,
    ) {
    }

    public function stock(Uuid $userId): int
    {
        return $this->inventory->items($userId)[ItemType::CoordinatesPardon->value] ?? 0;
    }

    public function consume(Uuid $userId): bool
    {
        return $this->inventory->consume($userId, ItemType::CoordinatesPardon);
    }
}
