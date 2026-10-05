<?php

declare(strict_types=1);

namespace App\EarlyAccess\Player;

/**
 * A suspension (or its lifting) refused before anything was written.
 */
final class SuspensionException extends \RuntimeException
{
    public const NOT_FOUND = 'not_found';
    /** An admin cannot suspend their own account. */
    public const SELF = 'cannot_suspend_self';
    /** Nor another admin's: take the role back first (app:admin:grant --revoke). */
    public const ADMIN = 'cannot_suspend_admin';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
