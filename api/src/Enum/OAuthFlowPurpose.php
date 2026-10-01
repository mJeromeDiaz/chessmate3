<?php

declare(strict_types=1);

namespace App\Enum;

use App\Security\OAuth\LichessOAuthClient;

enum OAuthFlowPurpose: string
{
    /** Sign in (or sign up) with the provider account. */
    case Login = 'login';

    /** Attach the provider account to the already signed-in user who started the flow. */
    case Link = 'link';

    /**
     * Ask the signed-in user's already linked provider account for more scopes (Lichess
     * study:read, to import private studies). The new token replaces the old one only when it
     * belongs to the same provider account.
     */
    case Grant = 'grant';

    /**
     * The scopes a grant flow asks for, by provider (none: no grant for that provider).
     *
     * @return list<string>
     */
    public static function grantScopes(AuthProvider $provider): array
    {
        return AuthProvider::Lichess === $provider ? [LichessOAuthClient::STUDY_READ] : [];
    }

    /** A flow that acts for the user who started it (signed in when starting). */
    public function needsUser(): bool
    {
        return self::Login !== $this;
    }
}
