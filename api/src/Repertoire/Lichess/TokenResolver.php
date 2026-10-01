<?php

declare(strict_types=1);

namespace App\Repertoire\Lichess;

use App\Entity\User;
use App\Enum\AuthProvider;
use App\Security\OAuth\LichessOAuthClient;
use App\Security\OAuth\OAuthTokenVault;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * The Lichess tokens a request may use, in order: the user's own (linked Lichess account, stored
 * encrypted since phase 1), then the application's (LICHESS_APP_TOKEN, a personal token of the
 * application's account, no scope). Tokens stay on the server.
 */
final class TokenResolver
{
    public function __construct(
        private readonly OAuthTokenVault $vault,
        #[Autowire('%env(default::LICHESS_APP_TOKEN)%')]
        #[\SensitiveParameter]
        private readonly ?string $applicationToken,
    ) {
    }

    /**
     * @return list<string>
     */
    public function candidates(User $user): array
    {
        $tokens = [];
        $own = $this->userToken($user);
        if (null !== $own) {
            $tokens[] = $own;
        }
        if (null !== $this->applicationToken && '' !== $this->applicationToken) {
            $tokens[] = $this->applicationToken;
        }

        return $tokens;
    }

    /**
     * The user's own token when it may read their studies (granted study:read), else none: the
     * application's token is never used for studies (it would read its own account's).
     */
    public function studyToken(User $user): ?string
    {
        foreach ($user->getAuthIdentities() as $identity) {
            $scopes = $identity->getMetadata()['scopes'] ?? [];
            if (AuthProvider::Lichess === $identity->getProvider() && \is_array($scopes) && \in_array(LichessOAuthClient::STUDY_READ, $scopes, true)) {
                return $this->vault->reveal($identity);
            }
        }

        return null;
    }

    public function userToken(User $user): ?string
    {
        foreach ($user->getAuthIdentities() as $identity) {
            if (AuthProvider::Lichess === $identity->getProvider()) {
                return $this->vault->reveal($identity);
            }
        }

        return null;
    }
}
