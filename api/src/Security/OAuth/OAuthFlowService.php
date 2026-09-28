<?php

declare(strict_types=1);

namespace App\Security\OAuth;

use App\Entity\OAuthFlow;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Enum\OAuthFlowPurpose;
use App\Repository\OAuthFlowRepository;
use App\Security\OAuth\Exception\OAuthFlowException;
use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;
use Symfony\Component\HttpFoundation\Request;

/**
 * Starts and completes OAuth authorization-code flows with PKCE, for any provider — see
 * {@see OAuthFlow} for how state and the PKCE verifier are kept without a session.
 */
final readonly class OAuthFlowService
{
    /** @var array<string, OAuthProviderClientInterface> */
    private array $clients;

    /**
     * @param iterable<OAuthProviderClientInterface> $clients
     */
    public function __construct(
        #[AutowireIterator('app.oauth_provider_client')]
        iterable $clients,
        private OAuthFlowRepository $repository,
        private OAuthFlowCookieFactory $cookieFactory,
    ) {
        $byProvider = [];

        foreach ($clients as $client) {
            $byProvider[$client->getProvider()->value] = $client;
        }

        $this->clients = $byProvider;
    }

    public function supports(AuthProvider $provider): bool
    {
        return isset($this->clients[$provider->value]);
    }

    public function start(AuthProvider $provider, OAuthFlowPurpose $purpose, ?User $user = null): StartedOAuthFlow
    {
        $this->repository->deleteExpired();

        $state = bin2hex(random_bytes(32));
        $binding = bin2hex(random_bytes(32));
        $authorization = $this->client($provider)->buildAuthorizationRequest($state);

        $flow = new OAuthFlow($provider, $purpose, $this->hash($binding), $this->hash($state), $authorization->codeVerifier, $user);
        $this->repository->save($flow);

        return new StartedOAuthFlow($authorization->url, $this->cookieFactory->create($binding, $flow->getExpiresAt()));
    }

    /**
     * Validates the callback against the flow this browser started, consumes the flow (single
     * use), then exchanges the code.
     *
     * @throws OAuthFlowException
     */
    public function complete(AuthProvider $provider, Request $request): CompletedOAuthFlow
    {
        if ($request->query->has('error')) {
            throw new OAuthFlowException(OAuthFlowException::CANCELLED);
        }

        $binding = $this->cookieFactory->readFrom($request);
        $state = $request->query->get('state');
        $code = $request->query->get('code');

        if (null === $binding || !\is_string($state) || '' === $state || !\is_string($code) || '' === $code) {
            throw new OAuthFlowException(OAuthFlowException::INVALID_STATE);
        }

        $flow = $this->repository->findOneByBindingHash($this->hash($binding));

        if (null === $flow
            || $flow->getProvider() !== $provider
            || !hash_equals($flow->getStateHash(), $this->hash($state))
            || !$this->repository->consumeIfActive($flow)
        ) {
            throw new OAuthFlowException(OAuthFlowException::INVALID_STATE);
        }

        return new CompletedOAuthFlow($flow, $this->client($provider)->fetchIdentity($code, $flow->getCodeVerifier()));
    }

    private function client(AuthProvider $provider): OAuthProviderClientInterface
    {
        return $this->clients[$provider->value] ?? throw new \LogicException(sprintf('No OAuth client for "%s".', $provider->value));
    }

    private function hash(string $value): string
    {
        return hash('sha256', $value);
    }
}
