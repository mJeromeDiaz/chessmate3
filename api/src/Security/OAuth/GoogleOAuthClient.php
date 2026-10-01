<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Enum\AuthProvider;
use App\Security\OAuth\Exception\OAuthFlowException;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Google through knpu's configured provider ({@see Provider\GooglePkceProvider}). Scopes are the
 * provider's OpenID Connect defaults: openid, email, profile.
 */
final readonly class GoogleOAuthClient implements OAuthProviderClientInterface
{
    public function __construct(private ClientRegistry $clientRegistry)
    {
    }

    #[\Override]
    public function getProvider(): AuthProvider
    {
        return AuthProvider::Google;
    }

    #[\Override]
    public function buildAuthorizationRequest(string $state, array $scopes = []): AuthorizationRequest
    {
        if ([] !== $scopes) {
            throw new \LogicException('No extra Google scope is ever asked.');
        }
        $provider = $this->provider();
        $url = $provider->getAuthorizationUrl(['state' => $state]);

        return new AuthorizationRequest($url, (string) $provider->getPkceCode());
    }

    #[\Override]
    public function fetchIdentity(string $code, string $codeVerifier): ExternalIdentity
    {
        $provider = $this->provider();
        $provider->setPkceCode($codeVerifier);

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $owner = $token instanceof AccessToken ? $provider->getResourceOwner($token) : null;
        } catch (\Throwable $exception) {
            // Invalid/expired/reused code, PKCE mismatch, network failure, malformed response...
            throw new OAuthFlowException(OAuthFlowException::PROVIDER_ERROR, $exception);
        }

        if (!$owner instanceof GoogleUser) {
            throw new OAuthFlowException(OAuthFlowException::PROVIDER_ERROR);
        }

        // Read defensively: GoogleUser's getters index the response without checking.
        $userInfo = $owner->toArray();
        $subject = $userInfo['sub'] ?? null;
        $email = $userInfo['email'] ?? null;
        $emailVerified = true === ($userInfo['email_verified'] ?? null);
        $name = $userInfo['name'] ?? null;

        if (!\is_string($subject) || '' === $subject) {
            throw new OAuthFlowException(OAuthFlowException::PROVIDER_ERROR);
        }

        return new ExternalIdentity(
            AuthProvider::Google,
            $subject,
            \is_string($email) ? $email : null,
            $emailVerified,
            ['name' => \is_string($name) ? $name : null, 'email_verified' => $emailVerified],
        );
    }

    #[\Override]
    public function revokeAccessToken(#[\SensitiveParameter] string $accessToken): void
    {
        // Nothing to do: the Google token is used once to read the profile and never kept.
    }

    private function provider(): AbstractProvider
    {
        return $this->clientRegistry->getClient(AuthProvider::Google->value)->getOAuth2Provider();
    }
}
