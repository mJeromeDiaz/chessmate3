<?php

declare(strict_types=1);

namespace App\ApiResource\Onboarding;

use App\Enum\Onboarding\Goal;
use App\Enum\Onboarding\Level;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The guided tour's answers, replaced at once (null: none).
 */
final class OnboardingInput
{
    #[Assert\Choice(callback: [self::class, 'levels'])]
    public ?string $level = null;

    #[Assert\Choice(callback: [self::class, 'goals'])]
    public ?string $goal = null;

    /**
     * @return list<string>
     */
    public static function levels(): array
    {
        return array_map(static fn (Level $level): string => $level->value, Level::cases());
    }

    /**
     * @return list<string>
     */
    public static function goals(): array
    {
        return array_map(static fn (Goal $goal): string => $goal->value, Goal::cases());
    }
}
