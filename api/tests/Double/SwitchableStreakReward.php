<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Gamification\Streak\StreakRewardInterface;
use App\Shop\Reward\DailyRewarder;
use Symfony\Component\Uid\Uuid;

/**
 * The daily chest in tests (wired in config/services.yaml, when@test): off by default, so that the
 * tests counting a balance or a ledger exactly never see its random crystals; the tests of the
 * chest switch it on (docs/SHOP.md, § 4 ter), then off in their tearDown.
 */
final class SwitchableStreakReward implements StreakRewardInterface
{
    public static bool $enabled = false;

    public function __construct(
        private readonly DailyRewarder $inner,
    ) {
    }

    public function grant(Uuid $userId, string $localDate, int $streak, ?int $badgeDays, \DateTimeImmutable $now): void
    {
        if (self::$enabled) {
            $this->inner->grant($userId, $localDate, $streak, $badgeDays, $now);
        }
    }
}
