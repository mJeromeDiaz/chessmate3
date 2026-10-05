<?php

declare(strict_types=1);

namespace App\Enum\EarlyAccess;

/**
 * Delivery of the invitation email of the current key. Stored as its value: add cases, never rename
 * one.
 */
enum EmailStatus: string
{
    /** Queued, not delivered yet (or being retried). */
    case Pending = 'pending';
    case Sent = 'sent';
    /** Every retry failed: the admin can resend (a new key). */
    case Failed = 'failed';
}
