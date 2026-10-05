<?php

declare(strict_types=1);

namespace App\Security\Account;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A session refused to a suspended account (docs/EARLY_ACCESS.md). 423 Locked, so that the SPA
 * tells it from the 403 of an unverified email. Only ever raised once the credentials (or the
 * provider's sign-in) are proven: it reveals nothing to someone who does not own the account.
 */
final class AccountSuspendedException extends HttpException
{
    public const REASON = 'account_suspended';

    public function __construct()
    {
        parent::__construct(Response::HTTP_LOCKED, 'This account is suspended.');
    }
}
