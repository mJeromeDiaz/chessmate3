<?php

declare(strict_types=1);

namespace App\EarlyAccess\Invitation\Message;

/**
 * Send the email of an invitation's key (async, retried). The key travels encrypted
 * ({@see \App\Security\Crypto\SecretBox}): the queue and the failure transport are database
 * tables, and a copy of the database must yield no usable key.
 */
final readonly class SendInvitationEmail
{
    public function __construct(
        public string $invitationId,
        public string $encryptedKey,
    ) {
    }
}
