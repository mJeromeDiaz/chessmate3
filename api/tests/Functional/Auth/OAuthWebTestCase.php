<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\AuditLogEntry;
use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\AuthProvider;
use Symfony\Component\HttpFoundation\Response;

/**
 * Provider-agnostic helpers for the OAuth flow tests (the fake providers simulate the consent step).
 */
abstract class OAuthWebTestCase extends AuthWebTestCase
{
    /**
     * @return array{string, string} state and PKCE challenge, as sent to the provider
     */
    protected function startOAuthFlow(AuthProvider $provider): array
    {
        $this->client->request('GET', '/api/auth/oauth/'.$provider->value.'/redirect');

        return $this->stateAndChallenge((string) $this->client->getResponse()->headers->get('Location'));
    }

    /**
     * @return array{string, string}
     */
    protected function stateAndChallenge(string $authorizationUrl): array
    {
        parse_str((string) parse_url($authorizationUrl, \PHP_URL_QUERY), $query);
        self::assertIsString($query['state'] ?? null);
        self::assertIsString($query['code_challenge'] ?? null);

        return [$query['state'], $query['code_challenge']];
    }

    /**
     * @param array<string, string> $query
     */
    protected function oauthCallback(AuthProvider $provider, array $query): Response
    {
        $this->client->request('GET', '/api/auth/oauth/'.$provider->value.'/callback?'.http_build_query($query));

        return $this->client->getResponse();
    }

    /**
     * Starts linking $provider from the profile and returns the provider's authorization URL.
     */
    protected function startLink(AuthProvider $provider, string $accessToken): string
    {
        $this->client->request('POST', '/api/profile/identities/'.$provider->value.'/link', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
            'HTTP_ACCEPT' => 'application/json',
        ]);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode());
        $authorizationUrl = $this->decodeJson($response)['authorizationUrl'] ?? null;
        self::assertIsString($authorizationUrl);

        return $authorizationUrl;
    }

    /**
     * @return array<mixed>
     */
    protected function spaOutcome(Response $response): array
    {
        self::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        self::assertStringStartsWith('http://localhost:9000/#/oauth/callback?', $location);
        parse_str(substr($location, (int) strpos($location, '?') + 1), $params);

        return $params;
    }

    protected function assertNoRefreshCookie(Response $response): void
    {
        foreach ($response->headers->getCookies() as $cookie) {
            self::assertNotSame('refresh_token', $cookie->getName());
        }
    }

    protected function findIdentity(AuthProvider $provider, string $providerUserId): AuthIdentity
    {
        $this->entityManager->clear();
        $identity = $this->entityManager->getRepository(AuthIdentity::class)->findOneBy(['provider' => $provider, 'providerUserId' => $providerUserId]);
        self::assertInstanceOf(AuthIdentity::class, $identity);

        return $identity;
    }

    protected function reloadUser(User $user): User
    {
        $this->entityManager->clear();
        $reloaded = $this->userRepository->find($user->getId());
        self::assertInstanceOf(User::class, $reloaded);

        return $reloaded;
    }

    protected function auditCount(AuditEventType $type): int
    {
        return $this->entityManager->getRepository(AuditLogEntry::class)->count(['eventType' => $type]);
    }
}
