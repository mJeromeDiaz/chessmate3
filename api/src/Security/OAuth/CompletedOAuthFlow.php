<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\OAuthFlow;

final readonly class CompletedOAuthFlow
{
    public function __construct(
        public OAuthFlow $flow,
        public ExternalIdentity $identity,
    ) {
    }
}
