<?php

declare(strict_types=1);

namespace App\Enum\EarlyAccess;

/**
 * What the invitation log records (docs/EARLY_ACCESS.md). Stored as its value: add cases, never
 * rename one.
 */
enum InvitationAction: string
{
    case KeyCreated = 'key_created';
    case KeySent = 'key_sent';
    /** A new key on the same invitation, the previous one stops working. */
    case KeyResent = 'key_resent';
    case KeySendFailed = 'key_send_failed';
    case KeyUsed = 'key_used';
    /** Someone tried the key after its expiry (logged then: expiry itself needs no cron). */
    case KeyExpired = 'key_expired';
    case KeyRevoked = 'key_revoked';
}
