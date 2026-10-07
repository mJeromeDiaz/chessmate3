<?php

declare(strict_types=1);

namespace App\ApiResource\Gamification;

use App\Gamification\Streak\StreakReminders;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The "streak in danger" reminder settings, replaced at once.
 */
final class StreakReminderInput
{
    public bool $enabled = true;

    /** Local hour it is sent at. */
    #[Assert\Range(min: StreakReminders::MIN_HOUR, max: StreakReminders::MAX_HOUR)]
    public int $hour = StreakReminders::DEFAULT_HOUR;

    /** Also by email (a verified address only). */
    public bool $email = false;
}
