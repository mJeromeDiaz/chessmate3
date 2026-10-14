<?php

declare(strict_types=1);

namespace App\Shop\Reward;

use App\Enum\Shop\RewardTier;

/**
 * What a session earns (docs/SHOP.md, § 4 bis), set by an admin: crowns per paid hour, the factor
 * of each tier (in %), the paid minutes of a local day.
 */
final readonly class Rule
{
    public const DEFAULT_CROWNS_PER_HOUR = 120;
    public const DEFAULT_PERFECT_PERCENT = 150;
    public const DEFAULT_NEAR_PERCENT = 100;
    public const DEFAULT_MISSED_PERCENT = 50;
    public const DEFAULT_DAILY_MINUTES = 60;

    public function __construct(
        public int $crownsPerHour = self::DEFAULT_CROWNS_PER_HOUR,
        public int $perfectPercent = self::DEFAULT_PERFECT_PERCENT,
        public int $nearPercent = self::DEFAULT_NEAR_PERCENT,
        public int $missedPercent = self::DEFAULT_MISSED_PERCENT,
        public int $dailyMinutes = self::DEFAULT_DAILY_MINUTES,
    ) {
    }

    public function percentOf(RewardTier $tier): int
    {
        return match ($tier) {
            RewardTier::Perfect => $this->perfectPercent,
            RewardTier::Near => $this->nearPercent,
            RewardTier::Missed => $this->missedPercent,
        };
    }

    /**
     * @return array{crownsPerHour: int, perfectPercent: int, nearPercent: int, missedPercent: int, dailyMinutes: int}
     */
    public function toArray(): array
    {
        return [
            'crownsPerHour' => $this->crownsPerHour,
            'perfectPercent' => $this->perfectPercent,
            'nearPercent' => $this->nearPercent,
            'missedPercent' => $this->missedPercent,
            'dailyMinutes' => $this->dailyMinutes,
        ];
    }
}
