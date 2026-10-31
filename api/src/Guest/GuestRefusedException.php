<?php

declare(strict_types=1);

namespace App\Guest;

use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * What stays an account's, refused to a guest (docs/GUEST.md): a 403 whose detail is the reason,
 * for the client to offer the sign-up.
 */
final class GuestRefusedException extends AccessDeniedHttpException
{
    /** A module, a session, a chapter after the first, a purchase: an account's. */
    public const ACCOUNT_REQUIRED = 'account_required';
    /** The day's endgame positions are played ({@see GuestPolicy::ENDGAME_POSITIONS_PER_DAY}). */
    public const ENDGAME_QUOTA = 'guest_endgame_quota';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
