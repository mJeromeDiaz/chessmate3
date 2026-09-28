<?php

declare(strict_types=1);

namespace App\Security\TwoFactor;

use App\Entity\MfaChallenge;
use App\Entity\User;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * One way of proving the second factor. The only implementation today is
 * {@see EmailCodeTwoFactorMethod}.
 *
 * Everything that is *generic* to a 2FA step — the mfa_pending token, the 5-attempt cap, expiry,
 * single use, concurrency safety — lives in {@see MfaChallengeService} and is shared by every
 * method. A method only decides how a code comes into existence and how a submitted one is checked:
 * - email: {@see self::begin()} generates a random code, stores its hash on the challenge and mails
 *   it; {@see self::verify()} compares against that hash.
 * - a future TOTP method: begin() would do nothing (the code is derived on the user's device from a
 *   shared secret), verify() would check the submitted code against that secret, supports() would
 *   return true only for users who enrolled, and canResend() would be false — no change needed in
 *   the service or the controllers.
 */
#[AutoconfigureTag(self::TAG)]
interface TwoFactorMethodInterface
{
    public const TAG = 'app.two_factor_method';

    /**
     * Stable identifier persisted on the challenge and returned to the client (e.g. "email").
     */
    public function getName(): string;

    /**
     * Whether this method can be used for this user at all.
     */
    public function supports(User $user): bool;

    /**
     * Prepares the challenge for a new code — called on creation and on every resend. May mutate
     * the challenge (e.g. store a code hash) before it is persisted.
     */
    public function begin(MfaChallenge $challenge): void;

    /**
     * Whether the user can ask for the code to be sent again.
     */
    public function canResend(): bool;

    /**
     * Checks a submitted code. Must be constant-time with respect to the secret.
     */
    public function verify(MfaChallenge $challenge, string $submittedCode): bool;
}
