<?php

declare(strict_types=1);

namespace App\Blindfold\Pardon;

use Symfony\Component\Uid\Uuid;

/**
 * The pardons for blindfold puzzles a player holds (docs/BLINDFOLD.md): owned by the Blindfold domain, implemented by the
 * store, so Blindfold never depends on the store.
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
