<?php

declare(strict_types=1);

namespace App\State\Gamification;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Gamification\StreakReminder;
use App\ApiResource\Gamification\StreakReminderInput;
use App\Gamification\Streak\StreakReminders;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * PUT /gamification/streak/reminder: replaces the settings (hour 18 to 23, validated as input),
 * within the profile's write budget (a profile setting).
 *
 * @implements ProcessorInterface<StreakReminderInput, StreakReminder>
 */
final class StreakReminderProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly StreakReminders $reminders,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $profileWriteLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StreakReminder
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->profileWriteLimiter, $user->getUserIdentifier());
        $settings = ['enabled' => $data->enabled, 'hour' => $data->hour, 'email' => $data->email];
        $this->reminders->update($user, $settings);

        return StreakReminder::of($settings);
    }
}
