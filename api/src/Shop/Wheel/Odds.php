<?php

declare(strict_types=1);

namespace App\Shop\Wheel;

use App\Enum\Shop\WheelGift;
use App\Enum\Shop\WheelPrize;
use Random\Randomizer;

/**
 * The gift wheel's odds (docs/SHOP.md, § 4 ter): a weight per prize (its chance: weight / sum),
 * and a gift's chance to be a streak freeze. The defaults (validated 2026-10-23): mostly 5
 * crystals, sometimes 30, rarely a gift or nothing, very rarely 300; a gift half freeze, half
 * costume. Pure.
 */
final readonly class Odds
{
    public const DEFAULT_C5 = 75;
    public const DEFAULT_C30 = 12;
    public const DEFAULT_C300 = 1;
    public const DEFAULT_GIFT = 7;
    public const DEFAULT_LOSE = 5;
    public const DEFAULT_FREEZE_PERCENT = 50;

    public function __construct(
        public int $c5 = self::DEFAULT_C5,
        public int $c30 = self::DEFAULT_C30,
        public int $c300 = self::DEFAULT_C300,
        public int $gift = self::DEFAULT_GIFT,
        public int $lose = self::DEFAULT_LOSE,
        public int $freezePercent = self::DEFAULT_FREEZE_PERCENT,
    ) {
    }

    /**
     * @return array<value-of<WheelPrize>, int>
     */
    public function weights(): array
    {
        return [
            WheelPrize::Crystals5->value => $this->c5,
            WheelPrize::Crystals30->value => $this->c30,
            WheelPrize::Crystals300->value => $this->c300,
            WheelPrize::Gift->value => $this->gift,
            WheelPrize::Lose->value => $this->lose,
        ];
    }

    /** At least one prize can come out. */
    public function isValid(): bool
    {
        return array_sum($this->weights()) > 0 && $this->freezePercent >= 0 && $this->freezePercent <= 100;
    }

    /**
     * A prize drawn by its weight.
     */
    public function draw(Randomizer $randomizer): WheelPrize
    {
        $roll = $randomizer->getInt(1, max(1, array_sum($this->weights())));
        foreach ($this->weights() as $prize => $weight) {
            $roll -= $weight;
            if ($roll <= 0 && $weight > 0) {
                return WheelPrize::from($prize);
            }
        }

        return WheelPrize::Crystals5;
    }

    /**
     * What a gift's chests hold.
     */
    public function gift(Randomizer $randomizer): WheelGift
    {
        return $randomizer->getInt(1, 100) <= $this->freezePercent ? WheelGift::Freeze : WheelGift::Costume;
    }

    /**
     * @return array{c5: int, c30: int, c300: int, gift: int, lose: int, freezePercent: int}
     */
    public function toArray(): array
    {
        return ['c5' => $this->c5, 'c30' => $this->c30, 'c300' => $this->c300, 'gift' => $this->gift, 'lose' => $this->lose, 'freezePercent' => $this->freezePercent];
    }
}
