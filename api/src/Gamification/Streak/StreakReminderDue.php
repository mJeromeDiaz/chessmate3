<?php

declare(strict_types=1);

namespace App\Gamification\Streak;

/**
 * Send the "streak in danger" reminder of a user (async: email and Web Push take time). Scalars
 * only; the due time is a UTC ATOM instant.
 */
final readonly class StreakReminderDue
{
    public function __construct(
        public string $userId,
        public string $dueAt,
    ) {
    }
}
