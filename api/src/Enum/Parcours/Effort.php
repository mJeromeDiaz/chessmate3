<?php

declare(strict_types=1);

namespace App\Enum\Parcours;

/**
 * How long a parcours is (docs/PARCOURS.md): exercises per tier, five tiers. Stored as its value:
 * add cases, never rename one.
 */
enum Effort: string
{
    /** Léger: 3 per tier, 15 in all. */
    case Light = 'light';
    /** Soutenu: 6 per tier, 30 in all. */
    case Steady = 'steady';
    /** Intense: 10 per tier, 50 in all. */
    case Intense = 'intense';

    public function perTier(): int
    {
        return match ($this) {
            self::Light => 3,
            self::Steady => 6,
            self::Intense => 10,
        };
    }

    /** XP for a parcours played to its end, instead of a session's bonus. */
    public function bonusXp(): int
    {
        return match ($this) {
            self::Light => 25,
            self::Steady => 50,
            self::Intense => 100,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $effort): string => $effort->value, self::cases());
    }
}
