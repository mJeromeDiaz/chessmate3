<?php

declare(strict_types=1);

namespace App\ApiResource\Gamification;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Put;
use App\State\Gamification\StreakReminderProcessor;
use App\State\Gamification\StreakReminderProvider;

/**
 * The signed-in user's "streak in danger" reminder (docs/NOTIFICATIONS.md, § 5): on by default at
 * 20 h, push only.
 */
#[ApiResource(
    shortName: 'GamificationStreakReminder',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/gamification/streak/reminder', provider: StreakReminderProvider::class),
        new Put(uriTemplate: '/gamification/streak/reminder', input: StreakReminderInput::class, read: false, processor: StreakReminderProcessor::class),
    ],
)]
final class StreakReminder
{
    public bool $enabled = true;
    /** Local hour it is sent at (18 to 23). */
    public int $hour = 20;
    /** Also by email (a verified address only). */
    public bool $email = false;

    /**
     * @param array{enabled: bool, hour: int, email: bool} $settings
     */
    public static function of(array $settings): self
    {
        $view = new self();
        $view->enabled = $settings['enabled'];
        $view->hour = $settings['hour'];
        $view->email = $settings['email'];

        return $view;
    }
}
