<?php

declare(strict_types=1);

namespace App\Security\Account;

/**
 * A refused step of the account deletion; the reason is the API's error code.
 */
final class AccountDeletionException extends \RuntimeException
{
    /** A deletion is already scheduled. */
    public const FROZEN = 'deletion_scheduled';
    /** No verified email to send the code to. */
    public const NO_EMAIL = 'no_verified_email';
    /** A code was sent less than 30 seconds ago. */
    public const TOO_SOON = 'resend_too_soon';
    /** No code, an expired one, or no attempt left: ask for a new one. */
    public const CODE_EXPIRED = 'code_expired';
    public const INVALID_CODE = 'invalid_code';
    /** No verified email, and the asking session signed in more than 10 minutes ago: sign in again. */
    public const SIGN_IN_REQUIRED = 'recent_sign_in_required';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
