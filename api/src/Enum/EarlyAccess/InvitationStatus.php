<?php

declare(strict_types=1);

namespace App\Enum\EarlyAccess;

/**
 * Where an invitation key stands (docs/EARLY_ACCESS.md). Never stored: derived from its dates, so
 * that a key expires on time without any cron.
 */
enum InvitationStatus: string
{
    /** Usable: neither used, revoked nor past its expiry. */
    case Pending = 'pending';
    case Used = 'used';
    case Expired = 'expired';
    /** Withdrawn by an admin before use. */
    case Revoked = 'revoked';
}
