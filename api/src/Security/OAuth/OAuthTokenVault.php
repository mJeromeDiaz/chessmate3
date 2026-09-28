<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\AuthIdentity;
use App\Enum\AuthProvider;
use App\Security\Crypto\SecretBox;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

/**
 * Keeps provider access tokens encrypted on their {@see AuthIdentity}, and revokes them at the
 * provider when they are replaced or the identity goes away — a token we no longer track must not
 * stay valid for a year at Lichess.
 */
final readonly class OAuthTokenVault
{
    /** @var array<string, OAuthProviderClientInterface> */
    private array $clients;

    /**
     * @param iterable<OAuthProviderClientInterface> $clients
     */
    public function __construct(
        #[AutowireIterator('app.oauth_provider_client')]
        iterable $clients,
        private SecretBox $secretBox,
        private LoggerInterface $logger,
    ) {
        $byProvider = [];

        foreach ($clients as $client) {
            $byProvider[$client->getProvider()->value] = $client;
        }

        $this->clients = $byProvider;
    }

    /**
     * Stores $accessToken (or nothing, if null) on the identity, revoking the one it replaces.
     */
    public function store(AuthIdentity $identity, #[\SensitiveParameter] ?string $accessToken): void
    {
        if (null === $accessToken) {
            return;
        }

        $this->revoke($identity);
        $identity->setAccessTokenEncrypted($this->secretBox->encrypt($accessToken));
    }

    /**
     * Revokes the stored token at the provider (best effort) and forgets it.
     */
    public function revoke(AuthIdentity $identity): void
    {
        $encrypted = $identity->getAccessTokenEncrypted();

        if (null === $encrypted) {
            return;
        }

        $identity->setAccessTokenEncrypted(null);

        try {
            $this->client($identity->getProvider())->revokeAccessToken($this->secretBox->decrypt($encrypted));
        } catch (\Throwable $exception) {
            // Never the token itself, nor the exception message (it could echo a request).
            $this->logger->warning('Could not revoke a stored OAuth access token.', [
                'provider' => $identity->getProvider()->value,
                'identity' => $identity->getId()->toRfc4122(),
                'exception' => $exception::class,
            ]);
        }
    }

    /**
     * Revokes a token we just obtained but won't keep (the flow ended in an error).
     */
    public function discard(ExternalIdentity $identity): void
    {
        if (null === $identity->accessToken) {
            return;
        }

        try {
            $this->client($identity->provider)->revokeAccessToken($identity->accessToken);
        } catch (\Throwable $exception) {
            $this->logger->warning('Could not revoke an unused OAuth access token.', [
                'provider' => $identity->provider->value,
                'exception' => $exception::class,
            ]);
        }
    }

    private function client(AuthProvider $provider): OAuthProviderClientInterface
    {
        return $this->clients[$provider->value] ?? throw new \LogicException(sprintf('No OAuth client for "%s".', $provider->value));
    }
}
