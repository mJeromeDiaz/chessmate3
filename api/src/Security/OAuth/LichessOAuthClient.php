<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Enum\AuthProvider;
use App\Security\OAuth\Exception\OAuthFlowException;
use App\Security\OAuth\Provider\LichessProvider;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use League\OAuth2\Client\Token\AccessToken;

/**
 * Lichess through knpu's configured provider ({@see LichessProvider}): PKCE, no client secret, no
 * scope (a grant flow asks for {@see self::STUDY_READ}). Lichess gives no email without the email:read scope, so the identity never carries one —
 * such an account has no email until its owner adds one along with a password.
 *
 * The access token is handed over to be kept, encrypted, for future game imports.
 */
final readonly class LichessOAuthClient implements OAuthProviderClientInterface
{
    /** Reads the user's private and unlisted studies (repertoire import). */
    public const STUDY_READ = 'study:read';

    /** Perfs worth showing on the profile; Lichess returns many more (variants, puzzles...). */
    private const RATED_PERFS = ['bullet', 'blitz', 'rapid', 'classical', 'correspondence'];

    public function __construct(private ClientRegistry $clientRegistry)
    {
    }

    #[\Override]
    public function getProvider(): AuthProvider
    {
        return AuthProvider::Lichess;
    }

    #[\Override]
    public function buildAuthorizationRequest(string $state, array $scopes = []): AuthorizationRequest
    {
        $provider = $this->provider();
        $url = $provider->getAuthorizationUrl(['state' => $state] + ([] === $scopes ? [] : ['scope' => $scopes]));

        return new AuthorizationRequest($url, (string) $provider->getPkceCode());
    }

    #[\Override]
    public function fetchIdentity(string $code, string $codeVerifier): ExternalIdentity
    {
        $provider = $this->provider();
        $provider->setPkceCode($codeVerifier);

        try {
            $token = $provider->getAccessToken('authorization_code', ['code' => $code]);
            $account = $token instanceof AccessToken ? $provider->getResourceOwner($token)->toArray() : null;
        } catch (\Throwable $exception) {
            throw new OAuthFlowException(OAuthFlowException::PROVIDER_ERROR, $exception);
        }

        $id = $account['id'] ?? null;
        $username = $account['username'] ?? null;

        if (!$token instanceof AccessToken || !\is_string($id) || '' === $id) {
            throw new OAuthFlowException(OAuthFlowException::PROVIDER_ERROR);
        }

        $expires = $token->getExpires();

        return new ExternalIdentity(
            AuthProvider::Lichess,
            $id,
            null,
            false,
            [
                'username' => \is_string($username) ? $username : $id,
                'ratings' => $this->ratings($account['perfs'] ?? null),
                'token_expires_at' => null !== $expires ? (new \DateTimeImmutable('@'.$expires))->format(\DATE_ATOM) : null,
            ],
            $token->getToken(),
        );
    }

    #[\Override]
    public function revokeAccessToken(#[\SensitiveParameter] string $accessToken): void
    {
        $this->provider()->revokeAccessToken($accessToken);
    }

    /**
     * @return array<string, array{rating: int, games: int, provisional: bool}>
     */
    private function ratings(mixed $perfs): array
    {
        $ratings = [];

        if (!\is_array($perfs)) {
            return $ratings;
        }

        foreach (self::RATED_PERFS as $perf) {
            $data = $perfs[$perf] ?? null;

            if (\is_array($data) && \is_int($data['rating'] ?? null)) {
                $ratings[$perf] = [
                    'rating' => $data['rating'],
                    'games' => \is_int($data['games'] ?? null) ? $data['games'] : 0,
                    'provisional' => true === ($data['prov'] ?? false),
                ];
            }
        }

        return $ratings;
    }

    private function provider(): LichessProvider
    {
        $provider = $this->clientRegistry->getClient(AuthProvider::Lichess->value)->getOAuth2Provider();

        if (!$provider instanceof LichessProvider) {
            throw new \LogicException('The "lichess" OAuth client must use '.LichessProvider::class.'.');
        }

        return $provider;
    }
}
