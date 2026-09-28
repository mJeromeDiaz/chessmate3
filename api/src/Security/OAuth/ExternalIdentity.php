<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Enum\AuthProvider;

/**
 * Who the provider says the user is, after a successful code exchange.
 */
final readonly class ExternalIdentity
{
    /**
     * @param array<string, mixed> $metadata non-secret profile data worth keeping (never a token)
     */
    public function __construct(
        public AuthProvider $provider,
        public string $providerUserId,
        public ?string $email,
        /** Whether the provider vouches for the email — the only case in which we use it as ours. */
        public bool $emailVerified,
        public array $metadata = [],
        /**
         * Only for providers whose token we keep (Lichess): stored encrypted, never in $metadata,
         * never logged.
         */
        #[\SensitiveParameter]
        public ?string $accessToken = null,
    ) {
    }

    public function trustedEmail(): ?string
    {
        return $this->emailVerified && null !== $this->email && '' !== $this->email ? strtolower($this->email) : null;
    }
}
