<?php

declare(strict_types=1);

namespace App\Training\Plan\Reminder;

/**
 * Send the reminder of one occurrence of a saved session (async: email and Web Push take time).
 * Scalars only; the occurrence is a UTC ATOM instant.
 */
final readonly class SessionReminderDue
{
    public function __construct(
        public string $planId,
        public string $occursAt,
    ) {
    }
}
