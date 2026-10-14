<?php

declare(strict_types=1);

namespace App\Gamification\Streak;

use Symfony\Component\Uid\Uuid;

/**
 * The daily streak's freezes a player holds (docs/SHOP.md, "Gel de série"): owned by the
 * Gamification domain, implemented by the store, so the streak never depends on the store.
 */
interface FreezeStockInterface
{
    /** The days the player's freezes still cover, all together. */
    public function available(Uuid $userId): int;

    /**
     * Uses $days of the player's freezes, in the caller's transaction: the smallest freeze that
     * covers them all, else the shortest ones first; the days left stay for later.
     *
     * @return bool false when the freezes do not cover $days (nothing used)
     */
    public function consume(Uuid $userId, int $days, \DateTimeImmutable $now): bool;
}
