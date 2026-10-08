<?php

declare(strict_types=1);

namespace App\EarlyAccess\Invitation;

/**
 * An admin action refused on an invitation, before anything was written.
 */
final class InvitationException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    /** Already used, or revoked: nothing more can be done with it. */
    public const CLOSED = 'invitation_closed';
    public const INVALID_EXPIRY = 'invalid_expiry';
    /** A waiting-list request that already led to an invitation. */
    public const ALREADY_INVITED = 'already_invited';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
