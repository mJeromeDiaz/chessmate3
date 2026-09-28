<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Security\OAuth\Provider\LichessProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GenericResourceOwner;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Stands in for Lichess's servers in tests (config/packages/knpu_oauth2_client.yaml, when@test).
 * The authorization URL and PKCE challenge come from the real provider; the token exchange,
 * /api/account and token revocation are simulated. Codes are single-use and bound to their PKCE
 * challenge, and every exchange issues a distinct token, as Lichess does.
 */
class FakeLichessProvider extends LichessProvider
{
    /** @var array<string, array{challenge: string, account: array<string, mixed>}> */
    private static array $codes = [];

    /** @var list<string> tokens issued by the simulated exchange, in order */
    public static array $issuedTokens = [];

    /** @var list<string> tokens revoked through DELETE /api/token */
    public static array $revokedTokens = [];

    public static bool $failRevocation = false;

    /**
     * Simulates the user approving on Lichess: returns the code Lichess would send to the callback.
     *
     * @param array<string, mixed> $account the /api/account response (id, username, perfs...)
     */
    public static function consent(string $codeChallenge, array $account): string
    {
        $code = bin2hex(random_bytes(8));
        self::$codes[$code] = ['challenge' => $codeChallenge, 'account' => $account];

        return $code;
    }

    public static function reset(): void
    {
        self::$codes = [];
        self::$issuedTokens = [];
        self::$revokedTokens = [];
        self::$failRevocation = false;
    }

    /**
     * @param mixed                $grant
     * @param array<string, mixed> $options
     */
    #[\Override]
    public function getAccessToken($grant, array $options = []): AccessTokenInterface
    {
        $code = $options['code'] ?? null;
        $entry = null;

        if (\is_string($code)) {
            $entry = self::$codes[$code] ?? null;
            unset(self::$codes[$code]);
        }

        $verifier = (string) $this->getPkceCode();
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

        if (null === $entry || '' === $verifier || !hash_equals($entry['challenge'], $challenge)) {
            throw new IdentityProviderException('invalid_grant', 400, ['error' => 'invalid_grant']);
        }

        $token = 'lio_'.bin2hex(random_bytes(16));
        self::$issuedTokens[] = $token;

        return new AccessToken(['access_token' => $token, 'token_type' => 'Bearer', 'expires_in' => 31536000, 'account' => $entry['account']]);
    }

    #[\Override]
    public function getResourceOwner(AccessToken $token): GenericResourceOwner
    {
        /** @var array<string, mixed> $account */
        $account = $token->getValues()['account'];

        return new GenericResourceOwner($account, 'id');
    }

    #[\Override]
    public function revokeAccessToken(#[\SensitiveParameter] string $token): void
    {
        if (self::$failRevocation) {
            throw new \RuntimeException('Lichess is down.');
        }

        self::$revokedTokens[] = $token;
    }
}
