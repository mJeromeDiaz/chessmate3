<?php

declare(strict_types=1);

namespace App\Gamification\Streak;

use Symfony\Component\Uid\Uuid;

/**
 * What the streak's day earns (docs/SHOP.md, § 4 ter, the daily chest): owned by the Gamification
 * domain, implemented by the store, so the streak never depends on the store.
 */
interface StreakRewardInterface
{
    /**
     * Rewards the player's first exercise of a local day, in the caller's transaction (idempotent
     * per day).
     *
     * @param int      $streak    the streak reached that day
     * @param int|null $badgeDays the days of the streak badge won that day, null on other days
     */
    public function grant(Uuid $userId, string $localDate, int $streak, ?int $badgeDays, \DateTimeImmutable $now): void;
}
