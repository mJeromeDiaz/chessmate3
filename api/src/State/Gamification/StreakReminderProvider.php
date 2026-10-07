<?php

declare(strict_types=1);

namespace App\State\Gamification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Gamification\StreakReminder;
use App\Gamification\Streak\StreakReminders;
use App\Security\AuthenticatedUser;

/**
 * GET /gamification/streak/reminder: the current user's settings (the defaults when never changed).
 *
 * @implements ProviderInterface<StreakReminder>
 */
final class StreakReminderProvider implements ProviderInterface
{
    public function __construct(
        private readonly StreakReminders $reminders,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): StreakReminder
    {
        return StreakReminder::of($this->reminders->settings($this->authenticatedUser->get()));
    }
}
