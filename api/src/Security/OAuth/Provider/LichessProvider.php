<?php

declare(strict_types=1);

namespace App\Security\OAuth\Provider;

use League\OAuth2\Client\Provider\AbstractProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Provider\ResourceOwnerInterface;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Lichess OAuth2 (https://lichess.org/api#tag/OAuth): a public client, so PKCE S256 is mandatory
 * and there is no client secret — whatever the configuration holds is never sent.
 *
 * No scope is requested: /api/account (id, username, ratings) needs none, and the token then grants
 * nothing beyond public data.
 */
class LichessProvider extends AbstractProvider
{
    public const BASE_URL = 'https://lichess.org';

    #[\Override]
    public function getBaseAuthorizationUrl(): string
    {
        return self::BASE_URL.'/oauth';
    }

    /**
     * @param array<string, mixed> $params
     */
    #[\Override]
    public function getBaseAccessTokenUrl(array $params): string
    {
        return self::BASE_URL.'/api/token';
    }

    #[\Override]
    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return self::BASE_URL.'/api/account';
    }

    /**
     * Revokes the token at Lichess (DELETE /api/token), e.g. when the identity is unlinked.
     *
     * @throws \Throwable on network failure or a non-2xx answer
     */
    public function revokeAccessToken(#[\SensitiveParameter] string $token): void
    {
        $request = $this->getAuthenticatedRequest('DELETE', self::BASE_URL.'/api/token', $token);
        $status = $this->getResponse($request)->getStatusCode();

        if ($status >= 300) {
            throw new \RuntimeException(sprintf('Lichess token revocation failed (HTTP %d).', $status));
        }
    }

    /**
     * @return list<string>
     */
    #[\Override]
    protected function getDefaultScopes(): array
    {
        return [];
    }

    #[\Override]
    protected function getScopeSeparator(): string
    {
        return ' ';
    }

    #[\Override]
    protected function getPkceMethod(): string
    {
        return self::PKCE_METHOD_S256;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<mixed>
     */
    #[\Override]
    protected function getAuthorizationParameters(array $options): array
    {
        $params = parent::getAuthorizationParameters($options);
        // No scope at all rather than "scope=", and no Google-era parameter Lichess doesn't know.
        if ('' === ($params['scope'] ?? null)) {
            unset($params['scope']);
        }
        unset($params['approval_prompt']);

        return $params;
    }

    /**
     * Public client: never send a secret, not even an empty one.
     *
     * @param array<mixed> $params
     */
    #[\Override]
    protected function getAccessTokenRequest(array $params): RequestInterface
    {
        unset($params['client_secret']);

        return parent::getAccessTokenRequest($params);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function getAuthorizationHeaders($token = null): array
    {
        $value = $token instanceof AccessToken ? $token->getToken() : $token;

        return \is_string($value) ? ['Authorization' => 'Bearer '.$value] : [];
    }

    /**
     * @param array<mixed>|string $data
     */
    #[\Override]
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if ($response->getStatusCode() >= 400 || (\is_array($data) && isset($data['error']))) {
            $error = \is_array($data) && \is_string($data['error'] ?? null) ? $data['error'] : $response->getReasonPhrase();

            throw new IdentityProviderException($error, $response->getStatusCode(), $data);
        }
    }

    /**
     * @param array<string, mixed> $response
     */
    #[\Override]
    protected function createResourceOwner(array $response, AccessToken $token): ResourceOwnerInterface
    {
        return new GenericResourceOwner($response, 'id');
    }
}
