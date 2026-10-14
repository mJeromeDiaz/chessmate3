<?php

declare(strict_types=1);

namespace App\Enum\Shop;

/**
 * How well a session went, for its crowns (docs/SHOP.md, § 4 bis): the thresholds of the end-of-run
 * result screen (perfect, 80 % or more, less). Stored as its value: add cases, never rename one.
 */
enum RewardTier: string
{
    /** Every exercise solved. */
    case Perfect = 'perfect';
    /** 80 to 99 % solved. */
    case Near = 'near';
    /** Less than 80 % solved. */
    case Missed = 'missed';

    /**
     * @param int $exercises at least 1
     */
    public static function of(int $solved, int $exercises): self
    {
        return match (true) {
            $solved >= $exercises => self::Perfect,
            // 80 %, in integers.
            5 * $solved >= 4 * $exercises => self::Near,
            default => self::Missed,
        };
    }
}
