<?php

declare(strict_types=1);

namespace App\Security\Registration;

use App\Entity\User;

/**
 * What opening an account takes beyond the sign-up itself (password or OAuth): today an early
 * access invitation key (App\EarlyAccess\Invitation\InvitationRedeemer, docs/EARLY_ACCESS.md).
 * Owned by the auth side so that it never depends on the domain implementing it.
 *
 * Two steps, because an OAuth sign-up starts with the key and opens the account a provider
 * round trip later: {@see self::admit()} checks the key when the sign-up starts, before anything
 * is written; {@see self::redeem()} consumes it in the transaction that opens the account.
 */
interface RegistrationGateInterface
{
    /**
     * Checks a key before the sign-up goes any further.
     *
     * @return string an opaque ticket to redeem it with: never the key, so it can be stored
     *
     * @throws RegistrationRefusedException missing, unknown, used, revoked or expired key
     */
    public function admit(#[\SensitiveParameter] ?string $key): string;

    /**
     * Consumes the ticket: of two sign-ups with the same key, only one gets through. Must run
     * inside the transaction that opens the account, before the account is persisted (a refusal
     * leaves nothing to undo). The caller then persists $user and flushes.
     *
     * @param User|null $user   the account being opened; null when none is (its email is taken)
     * @param string    $method how it signs up: password, google, lichess
     *
     * @throws RegistrationRefusedException the key stopped being usable since it was admitted
     */
    public function redeem(string $ticket, ?User $user, string $method): void;
}
