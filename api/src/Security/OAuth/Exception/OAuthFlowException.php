<?php

declare(strict_types=1);

namespace App\Security\OAuth\Exception;

/**
 * An OAuth flow that can't complete. The reason is a stable, non-sensitive code handed to the SPA
 * (in the redirect's URL fragment) so it can show the right message.
 */
final class OAuthFlowException extends \RuntimeException
{
    /** The user refused, or the provider returned an error, at the consent screen. */
    public const CANCELLED = 'cancelled';
    /** Missing/unknown/expired/replayed flow, or state mismatch — includes login-CSRF attempts. */
    public const INVALID_STATE = 'invalid_state';
    public const PROVIDER_ERROR = 'provider_error';
    /** Login: the provider's verified email belongs to an account this identity isn't linked to. */
    public const ACCOUNT_EXISTS = 'account_exists';
    /** Link: this provider account is already linked to another user. */
    public const IDENTITY_IN_USE = 'identity_in_use';
    /** Link: the user already has a different account of this provider linked. */
    public const PROVIDER_ALREADY_LINKED = 'provider_already_linked';
    /** Lost a race with a concurrent request creating the same identity or email. */
    public const CONFLICT = 'conflict';

    /** A grant asked for a provider the user has not linked. */
    public const NOT_LINKED = 'not_linked';

    /** A grant came back from another provider account than the linked one. */
    public const IDENTITY_MISMATCH = 'identity_mismatch';

    public function __construct(public readonly string $reason, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('OAuth flow failed: %s.', $reason), 0, $previous);
    }
}
