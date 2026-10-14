<?php

declare(strict_types=1);

namespace App\PuzzleStreak\Aid;

use App\Enum\PuzzleStreak\Aid;
use Symfony\Component\Uid\Uuid;

/**
 * The puzzle streak aids a player holds (docs/PUZZLE_STREAK.md, "Aides de la boutique"): owned by
 * the PuzzleStreak domain, implemented by the store, so the streak never depends on the store.
 */
interface AidStockInterface
{
    /**
     * @return array{help: int, pass: int}
     */
    public function stock(Uuid $userId): array;

    /**
     * Spends one $aid, in the caller's transaction.
     *
     * @return bool false when the player has none (nothing spent)
     */
    public function consume(Uuid $userId, Aid $aid): bool;
}
