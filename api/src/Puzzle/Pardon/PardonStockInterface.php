<?php

declare(strict_types=1);

namespace App\Puzzle\Pardon;

use Symfony\Component\Uid\Uuid;

/**
 * The pardons for rated puzzles a player holds (docs/PUZZLES.md, "Erreur pardonnée"): owned by the
 * Puzzle domain, implemented by the store, so puzzles never depend on the store.
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
