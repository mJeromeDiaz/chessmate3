<?php

declare(strict_types=1);

namespace App\Coordinates\Pardon;

use Symfony\Component\Uid\Uuid;

/**
 * The pardons for coordinates series a player holds (docs/COORDINATES.md): owned by the Coordinates domain, implemented by the
 * store, so Coordinates never depends on the store.
 */
interface PardonStockInterface
{
    public function stock(Uuid $userId): int;

    /**
     * Spends one, in the caller's transaction.
     *
     * @return bool false when the player has none (nothing spent)
     */
    public function consume(Uuid $userId): bool;
}
