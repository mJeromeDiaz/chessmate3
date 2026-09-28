<?php

declare(strict_types=1);

namespace App\Security\OAuth\Provider;

use League\OAuth2\Client\Provider\Google;

/**
 * league/oauth2-google with PKCE (S256) turned on — the base provider leaves it off. With it,
 * {@see Google::getAuthorizationUrl()} generates a code verifier and sends its challenge, and the
 * token request sends the verifier back.
 */
class GooglePkceProvider extends Google
{
    #[\Override]
    protected function getPkceMethod(): string
    {
        return self::PKCE_METHOD_S256;
    }
}
