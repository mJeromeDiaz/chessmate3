<?php

declare(strict_types=1);

namespace App\Security\TwoFactor\Exception;

/**
 * Covers every reason a pending token doesn't lead to a usable challenge — unknown, expired,
 * already consumed, or locked out after too many failed attempts — as a single outcome. The client
 * always sees the same generic error and has to start over from login; distinguishing the cases in
 * the response would leak state about a challenge it doesn't already hold.
 */
final class MfaChallengeNotFoundException extends \RuntimeException
{
}
