<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\AuthProvider;
use App\Security\Crypto\SecretBox;
use App\Tests\Double\FakeLichessProvider;
use Symfony\Component\HttpFoundation\Response;

final class LichessOAuthTest extends OAuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';

    private const ACCOUNT = [
        'id' => 'magnus',
        'username' => 'Magnus',
        'perfs' => [
            'blitz' => ['games' => 1200, 'rating' => 2250, 'rd' => 45, 'prog' => 3],
            'rapid' => ['games' => 12, 'rating' => 1900, 'rd' => 110, 'prog' => 0, 'prov' => true],
            'puzzle' => ['games' => 500, 'rating' => 2400, 'rd' => 60, 'prog' => 0],
        ],
        'email' => 'should-be-ignored@example.com',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        FakeLichessProvider::reset();
    }

    public function testRedirectSendsTheBrowserToLichessWithPkceAndNoScope(): void
    {
        $this->client->request('GET', '/api/auth/oauth/lichess/redirect');
        $response = $this->client->getResponse();

        self::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        self::assertStringStartsWith('https://lichess.org/oauth?', $location);

        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('chessmate-test', $query['client_id'] ?? null);
        self::assertSame('http://localhost/api/auth/oauth/lichess/callback', $query['redirect_uri'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        self::assertArrayNotHasKey('scope', $query);
        self::assertArrayNotHasKey('client_secret', $query);
        self::assertArrayNotHasKey('approval_prompt', $query);

        self::assertSame('lax', $this->findResponseCookie($response, 'oauth_flow')->getSameSite());
    }

    public function testFirstLoginCreatesAnAccountWithoutEmailAndKeepsTheTokenEncrypted(): void
    {
        $response = $this->loginWithLichess(self::ACCOUNT);

        self::assertSame(['status' => 'success', 'mode' => 'login', 'provider' => 'lichess'], $this->spaOutcome($response));
        self::assertEmailCount(0);

        $identity = $this->identity('magnus');
        $user = $identity->getUser();
        // Lichess vouches for no email (and we don't ask for one): even one in the response is ignored.
        self::assertNull($user->getEmail());
        self::assertNull($identity->getProviderEmail());
        self::assertFalse($user->hasPassword());

        $metadata = $identity->getMetadata();
        self::assertSame('Magnus', $metadata['username']);
        // assertEquals: MySQL's JSON type doesn't keep key order.
        self::assertEquals([
            'blitz' => ['rating' => 2250, 'games' => 1200, 'provisional' => false],
            'rapid' => ['rating' => 1900, 'games' => 12, 'provisional' => true],
        ], $metadata['ratings']);
        self::assertIsString($metadata['token_expires_at']);

        // The token is stored encrypted, and only there.
        $issued = FakeLichessProvider::$issuedTokens[0];
        $encrypted = $identity->getAccessTokenEncrypted();
        self::assertIsString($encrypted);
        self::assertStringNotContainsString($issued, $encrypted);
        self::assertStringNotContainsString($issued, (string) json_encode($metadata));
        self::assertSame($issued, $this->secretBox()->decrypt($encrypted));

        $accessToken = $this->decodeJson($this->refresh())['accessToken'] ?? null;
        self::assertIsString($accessToken);
        self::assertSame(200, $this->statusWithAccessToken($accessToken));
    }

    public function testReturningLoginReplacesTheStoredTokenAndRevokesTheOldOne(): void
    {
        $this->loginWithLichess(self::ACCOUNT);
        $this->client->getCookieJar()->clear();

        $account = self::ACCOUNT;
        $account['perfs']['blitz']['rating'] = 2300;
        $this->loginWithLichess($account);

        self::assertSame(1, $this->userRepository->count([]));
        [$first, $second] = FakeLichessProvider::$issuedTokens;
        self::assertSame([$first], FakeLichessProvider::$revokedTokens);

        $identity = $this->identity('magnus');
        self::assertSame($second, $this->secretBox()->decrypt((string) $identity->getAccessTokenEncrypted()));
        $ratings = $identity->getMetadata()['ratings'] ?? null;
        self::assertIsArray($ratings);
        $blitz = $ratings['blitz'] ?? null;
        self::assertIsArray($blitz);
        self::assertSame(2300, $blitz['rating'] ?? null);
    }

    public function testLoginStillWorksWhenRevokingTheOldTokenFails(): void
    {
        $this->loginWithLichess(self::ACCOUNT);
        $this->client->getCookieJar()->clear();
        FakeLichessProvider::$failRevocation = true;

        self::assertSame('success', $this->spaOutcome($this->loginWithLichess(self::ACCOUNT))['status']);
    }

    public function testCallbackWithoutTheFlowCookieIsRejected(): void
    {
        [$state, $challenge] = $this->startOAuthFlow(AuthProvider::Lichess);
        $code = FakeLichessProvider::consent($challenge, self::ACCOUNT);
        $this->client->getCookieJar()->clear();

        $response = $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => $code]);

        self::assertSame('invalid_state', $this->spaOutcome($response)['reason'] ?? null);
        $this->assertNoRefreshCookie($response);
        self::assertSame(0, $this->userRepository->count([]));
    }

    /**
     * A flow started for Google can't be completed on the Lichess callback.
     */
    public function testFlowOfAnotherProviderIsRejected(): void
    {
        [$state, $challenge] = $this->startOAuthFlow(AuthProvider::Google);
        $code = FakeLichessProvider::consent($challenge, self::ACCOUNT);

        $response = $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => $code]);

        self::assertSame('invalid_state', $this->spaOutcome($response)['reason'] ?? null);
    }

    public function testCodeBoundToAnotherPkceChallengeIsRejected(): void
    {
        [$state] = $this->startOAuthFlow(AuthProvider::Lichess);
        $code = FakeLichessProvider::consent('some-other-challenge', self::ACCOUNT);

        $response = $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => $code]);

        self::assertSame('provider_error', $this->spaOutcome($response)['reason'] ?? null);
        self::assertSame(0, $this->userRepository->count([]));
    }

    public function testSignedInUserLinksLichessFromTheProfile(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        $response = $this->linkWithLichess($accessToken, self::ACCOUNT);

        self::assertSame(['status' => 'success', 'mode' => 'link', 'provider' => 'lichess'], $this->spaOutcome($response));
        $this->assertNoRefreshCookie($response);
        $identity = $this->identity('magnus');
        self::assertSame($user->getId()->toRfc4122(), $identity->getUser()->getId()->toRfc4122());
        self::assertNotNull($identity->getAccessTokenEncrypted());
        self::assertSame(1, $this->auditCount(AuditEventType::AccountLinked));
        self::assertQueuedEmailCount(1);
    }

    public function testLichessAccountOfSomeoneElseCannotBeLinkedAndItsFreshTokenIsRevoked(): void
    {
        $this->loginWithLichess(self::ACCOUNT);
        $this->client->getCookieJar()->clear();
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        $response = $this->linkWithLichess($accessToken, self::ACCOUNT);

        self::assertSame('identity_in_use', $this->spaOutcome($response)['reason'] ?? null);
        [$ownersToken, $unusedToken] = FakeLichessProvider::$issuedTokens;
        // Only the token nobody keeps is revoked; the owner's stays valid and stored.
        self::assertSame([$unusedToken], FakeLichessProvider::$revokedTokens);
        self::assertSame($ownersToken, $this->secretBox()->decrypt((string) $this->identity('magnus')->getAccessTokenEncrypted()));
        self::assertNull($this->identity('magnus')->getUser()->getEmail());
    }

    public function testNoTokenOrSecretEverReachesTheAuditLog(): void
    {
        $this->loginWithLichess(self::ACCOUNT);

        $rows = $this->entityManager->getConnection()->fetchAllAssociative('SELECT metadata FROM audit_log_entry');
        self::assertNotEmpty($rows);
        self::assertStringNotContainsString(FakeLichessProvider::$issuedTokens[0], (string) json_encode($rows));
    }

    /**
     * @param array<string, mixed> $account
     */
    private function loginWithLichess(array $account): Response
    {
        [$state, $challenge] = $this->startOAuthFlow(AuthProvider::Lichess);

        return $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => FakeLichessProvider::consent($challenge, $account)]);
    }

    /**
     * @param array<string, mixed> $account
     */
    private function linkWithLichess(string $accessToken, array $account): Response
    {
        [$state, $challenge] = $this->stateAndChallenge($this->startLink(AuthProvider::Lichess, $accessToken));

        return $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => FakeLichessProvider::consent($challenge, $account)]);
    }

    private function identity(string $lichessId): AuthIdentity
    {
        return $this->findIdentity(AuthProvider::Lichess, $lichessId);
    }

    private function secretBox(): SecretBox
    {
        $key = $_SERVER['OAUTH_TOKEN_ENCRYPTION_KEY'] ?? null;
        self::assertIsString($key);

        return new SecretBox($key);
    }
}
