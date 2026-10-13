<?php

declare(strict_types=1);

namespace App\Enum\Onboarding;

/**
 * The level a player declares in the guided tour (docs/ONBOARDING.md), by Lichess rapid rating.
 * Stored as its value: add cases, never rename one.
 */
enum Level: string
{
    case Beginner = 'beginner';
    case Amateur = 'amateur';
    case Confirmed = 'confirmed';
    case Expert = 'expert';
    case Pro = 'pro';

    /** The puzzle rating it starts from, while no rated puzzle was played. */
    public function seedRating(): int
    {
        return match ($this) {
            self::Beginner => 1000,
            self::Amateur => 1400,
            self::Confirmed => 1800,
            self::Expert => 2200,
            self::Pro => 2500,
        };
    }

    /** The level of a Lichess rapid (else blitz, else classical) rating. */
    public static function ofLichessRating(int $rating): self
    {
        return match (true) {
            $rating < 1200 => self::Beginner,
            $rating < 1600 => self::Amateur,
            $rating < 2000 => self::Confirmed,
            $rating < 2600 => self::Expert,
            default => self::Pro,
        };
    }
}
