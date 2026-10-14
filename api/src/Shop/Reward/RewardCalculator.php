<?php

declare(strict_types=1);

namespace App\Shop\Reward;

use App\Enum\Shop\RewardTier;

/**
 * The crowns of a completed session (docs/SHOP.md, § 4 bis), pure arithmetic. Each run pays its
 * real duration, but at most one minute per exercise finished (a clock left running earns
 * nothing); the free study pays nothing and does not count in the tier.
 */
final class RewardCalculator
{
    /** The most a finished exercise pays. */
    public const SECONDS_PER_EXERCISE = 60;

    /**
     * @param list<array{durationMs: int, itemCount: int, successCount: int}> $runs the session's
     *                                                                              rewarded runs
     *
     * @return array{paidSeconds: int, itemCount: int, successCount: int}
     */
    public static function tally(array $runs): array
    {
        $paid = $items = $solved = 0;
        foreach ($runs as $run) {
            $items += $run['itemCount'];
            $solved += $run['successCount'];
            $paid += min(intdiv(max(0, $run['durationMs']), 1000), $run['itemCount'] * self::SECONDS_PER_EXERCISE);
        }

        return ['paidSeconds' => $paid, 'itemCount' => $items, 'successCount' => $solved];
    }

    /**
     * The paid seconds the day still allows.
     */
    public static function capped(int $paidSeconds, int $usedTodaySeconds, Rule $rule): int
    {
        return max(0, min($paidSeconds, $rule->dailyMinutes * 60 - $usedTodaySeconds));
    }

    /**
     * Crowns for paid seconds at a tier, rounded to the nearest.
     */
    public static function crowns(int $paidSeconds, RewardTier $tier, Rule $rule): int
    {
        // seconds × crowns per hour × percent / (3 600 × 100), rounded half up.
        return intdiv($paidSeconds * $rule->crownsPerHour * $rule->percentOf($tier) + 180_000, 360_000);
    }
}
