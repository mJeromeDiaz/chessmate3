<?php

declare(strict_types=1);

namespace App\Security\Registration;

/**
 * A sign-up refused by the {@see RegistrationGateInterface}. The reason is a stable code shown to
 * the SPA: it reveals nothing about accounts, only about the key (which nobody can guess).
 */
final class RegistrationRefusedException extends \RuntimeException
{
    /** No key: new accounts are by invitation only. */
    public const REQUIRED = 'invitation_required';
    /** Malformed, unknown, used or revoked. */
    public const INVALID = 'invitation_invalid';
    public const EXPIRED = 'invitation_expired';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(match ($reason) {
            self::REQUIRED => 'An invitation key is required to create an account.',
            self::EXPIRED => 'This invitation key has expired.',
            default => 'This invitation key is not valid.',
        }, 0, $previous);
    }
}
