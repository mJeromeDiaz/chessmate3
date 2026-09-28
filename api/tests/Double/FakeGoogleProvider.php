<?php

declare(strict_types=1);

namespace App\Tests\Double;

use App\Security\OAuth\Provider\GooglePkceProvider;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use League\OAuth2\Client\Token\AccessTokenInterface;

/**
 * Stands in for Google's servers in tests (wired in config/packages/knpu_oauth2_client.yaml,
 * when@test). Everything up to the network is the real provider — authorization URL, state, PKCE
 * challenge — only the token and userinfo calls are simulated, and they enforce what Google does:
 * a code is single-use and only redeemable with the verifier matching its challenge.
 */
class FakeGoogleProvider extends GooglePkceProvider
{
    /** @var array<string, array{challenge: string, user: array<string, mixed>}> */
    private static array $codes = [];

    /**
     * Simulates the user consenting on Google's page: returns the code Google would append to the
     * callback URL.
     *
     * @param array<string, mixed> $userInfo the userinfo response (sub, email, email_verified, name...)
     */
    public static function consent(string $codeChallenge, array $userInfo): string
    {
        $code = bin2hex(random_bytes(8));
        self::$codes[$code] = ['challenge' => $codeChallenge, 'user' => $userInfo];

        return $code;
    }

    public static function reset(): void
    {
        self::$codes = [];
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

        return new AccessToken(['access_token' => 'fake-access-token', 'userinfo' => $entry['user']]);
    }

    #[\Override]
    public function getResourceOwner(AccessToken $token): GoogleUser
    {
        /** @var array<string, mixed> $userInfo */
        $userInfo = $token->getValues()['userinfo'];

        return new GoogleUser($userInfo);
    }
}
