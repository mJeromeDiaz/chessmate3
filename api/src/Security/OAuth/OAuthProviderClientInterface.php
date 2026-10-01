<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Enum\AuthProvider;
use App\Security\OAuth\Exception\OAuthFlowException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * The provider-specific half of an OAuth flow: building the authorization URL (with PKCE) and
 * turning a callback's code into an {@see ExternalIdentity}. Everything else — state, browser
 * binding, what to do with the identity — is shared ({@see OAuthFlowService}, {@see OAuthAccountService}).
 */
#[AutoconfigureTag('app.oauth_provider_client')]
interface OAuthProviderClientInterface
{
    public function getProvider(): AuthProvider;

    /**
     * @param list<string> $scopes more scopes than the provider's defaults (a grant flow)
     */
    public function buildAuthorizationRequest(string $state, array $scopes = []): AuthorizationRequest;

    /**
     * @throws OAuthFlowException on any provider-side failure (reason "provider_error")
     */
    public function fetchIdentity(string $code, string $codeVerifier): ExternalIdentity;

    /**
     * Revokes, at the provider, an access token we kept ({@see ExternalIdentity::$accessToken}).
     * Best effort: callers ignore failures, the identity is removed on our side regardless.
     *
     * @throws \Throwable on failure
     */
    public function revokeAccessToken(string $accessToken): void;
}
