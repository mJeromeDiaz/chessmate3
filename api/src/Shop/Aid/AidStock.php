<?php

declare(strict_types=1);

namespace App\Shop\Aid;

use App\Enum\PuzzleStreak\Aid;
use App\Enum\Shop\ItemType;
use App\PuzzleStreak\Aid\AidStockInterface;
use App\Shop\Inventory\Inventory;
use Symfony\Component\Uid\Uuid;

/**
 * The store's puzzle streak helps and passes (docs/SHOP.md), spent one at a time.
 */
final readonly class AidStock implements AidStockInterface
{
    public function __construct(
        private Inventory $inventory,
    ) {
    }

    public function stock(Uuid $userId): array
    {
        $items = $this->inventory->items($userId);

        return [
            'help' => $items[ItemType::PuzzleStreakHelp->value] ?? 0,
            'pass' => $items[ItemType::PuzzleStreakPass->value] ?? 0,
        ];
    }

    public function consume(Uuid $userId, Aid $aid): bool
    {
        return $this->inventory->consume($userId, match ($aid) {
            Aid::Help => ItemType::PuzzleStreakHelp,
            Aid::Pass => ItemType::PuzzleStreakPass,
        });
    }
}
