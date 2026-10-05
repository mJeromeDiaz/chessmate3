<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\AuthIdentity;
use App\Entity\EarlyAccess\InvitationLog;
use App\Entity\OAuthFlow;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\AuthProvider;
use App\Enum\EarlyAccess\InvitationAction;
use App\Enum\EarlyAccess\InvitationStatus;
use App\Tests\Double\FakeGoogleProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class GoogleOAuthTest extends OAuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';

    protected function setUp(): void
    {
        parent::setUp();
        FakeGoogleProvider::reset();
    }

    public function testRedirectSendsTheBrowserToGoogleWithStateAndPkce(): void
    {
        $this->client->request('GET', '/api/auth/oauth/google/redirect');
        $response = $this->client->getResponse();

        self::assertSame(302, $response->getStatusCode());
        $location = (string) $response->headers->get('Location');
        self::assertStringStartsWith('https://accounts.google.com/o/oauth2/v2/auth?', $location);

        parse_str((string) parse_url($location, \PHP_URL_QUERY), $query);
        self::assertSame('code', $query['response_type'] ?? null);
        self::assertSame('test-client-id', $query['client_id'] ?? null);
        self::assertSame('http://localhost/api/auth/oauth/google/callback', $query['redirect_uri'] ?? null);
        self::assertSame('openid email profile', $query['scope'] ?? null);
        self::assertSame('S256', $query['code_challenge_method'] ?? null);
        [$state, $challenge] = $this->stateAndChallenge($location);
        self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]{43}$/', $challenge);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $state);

        $cookie = $this->findResponseCookie($response, 'oauth_flow');
        self::assertTrue($cookie->isHttpOnly());
        self::assertSame('lax', $cookie->getSameSite());
        self::assertSame('/api/auth/oauth', $cookie->getPath());

        // Only hashes are stored.
        $flow = $this->entityManager->getRepository(OAuthFlow::class)->findOneBy([]);
        self::assertInstanceOf(OAuthFlow::class, $flow);
        self::assertSame(hash('sha256', $state), $flow->getStateHash());
        self::assertSame(0, $this->entityManager->getRepository(OAuthFlow::class)->count(['bindingHash' => $cookie->getValue()]));
    }

    public function testFirstLoginCreatesAVerifiedAccountAndSignsInWithoutAnyTokenInTheUrl(): void
    {
        $response = $this->loginWithGoogle(['sub' => 'g-1', 'email' => 'Alice@Gmail.com', 'email_verified' => true, 'name' => 'Alice']);

        self::assertSame(['status' => 'success', 'mode' => 'login', 'provider' => 'google'], $this->spaOutcome($response));
        $refreshToken = $this->extractCookieValue($response, 'refresh_token');
        self::assertStringNotContainsString($refreshToken, (string) $response->headers->get('Location'));
        self::assertTrue($this->findResponseCookie($response, 'oauth_flow')->isCleared());

        // No application 2FA for OAuth logins.
        self::assertEmailCount(0);

        $user = $this->userRepository->findOneByEmail('alice@gmail.com');
        self::assertInstanceOf(User::class, $user);
        self::assertTrue($user->isEmailVerified());
        self::assertFalse($user->hasPassword());
        $identity = $this->identity('g-1');
        self::assertSame($user->getId()->toRfc4122(), $identity->getUser()->getId()->toRfc4122());

        // The SPA then gets its access token from the refresh endpoint.
        $accessToken = $this->decodeJson($this->refresh())['accessToken'] ?? null;
        self::assertIsString($accessToken);
        self::assertSame(200, $this->statusWithAccessToken($accessToken));

        self::assertSame(1, $this->auditCount(AuditEventType::OauthLoginSuccess));
    }

    public function testReturningUserSignsIntoTheSameAccount(): void
    {
        $this->loginWithGoogle(['sub' => 'g-1', 'email' => 'alice@gmail.com', 'email_verified' => true, 'name' => 'Alice']);
        $this->client->getCookieJar()->clear();

        $response = $this->loginWithGoogle(['sub' => 'g-1', 'email' => 'alice@gmail.com', 'email_verified' => true, 'name' => 'Alice']);

        self::assertSame('success', $this->spaOutcome($response)['status']);
        self::assertSame(1, $this->userRepository->count([]));
    }

    public function testUnverifiedEmailIsNeverUsedAsTheAccountEmail(): void
    {
        $response = $this->loginWithGoogle(['sub' => 'g-2', 'email' => 'bob@example.com', 'email_verified' => false, 'name' => 'Bob']);

        self::assertSame('success', $this->spaOutcome($response)['status']);
        self::assertNull($this->userRepository->findOneByEmail('bob@example.com'));
        $identity = $this->identity('g-2');
        self::assertNull($identity->getUser()->getEmail());
        self::assertSame('bob@example.com', $identity->getProviderEmail());
    }

    /**
     * The account-takeover scenario the spec forbids: a Google account with the same email must
     * not get into an existing password account.
     */
    public function testVerifiedEmailOfAnExistingAccountIsNotLinkedAutomatically(): void
    {
        $this->createVerifiedUser('alice@gmail.com', self::PASSWORD);

        $response = $this->loginWithGoogle(['sub' => 'g-1', 'email' => 'alice@gmail.com', 'email_verified' => true, 'name' => 'Alice']);

        self::assertSame(['status' => 'error', 'mode' => 'login', 'provider' => 'google', 'reason' => 'account_exists'], $this->spaOutcome($response));
        $this->assertNoRefreshCookie($response);
        self::assertSame(0, $this->entityManager->getRepository(AuthIdentity::class)->count([]));
        self::assertSame(1, $this->auditCount(AuditEventType::OauthLoginFailure));
    }

    public function testUnverifiedEmailOfAnExistingAccountGetsASeparateAccount(): void
    {
        $existing = $this->createVerifiedUser('alice@gmail.com', self::PASSWORD);

        $this->loginWithGoogle(['sub' => 'g-1', 'email' => 'alice@gmail.com', 'email_verified' => false, 'name' => 'Mallory']);

        self::assertNotSame($existing->getId()->toRfc4122(), $this->identity('g-1')->getUser()->getId()->toRfc4122());
        self::assertSame(0, $this->reloadUser($existing)->getAuthIdentities()->count());
    }

    /**
     * Login CSRF: the attacker completes Google's consent with their own account and sends the
     * victim the callback URL. The victim's browser has no flow cookie for it.
     */
    public function testCallbackWithoutTheFlowCookieIsRejected(): void
    {
        [$state, $challenge] = $this->startFlow();
        $code = FakeGoogleProvider::consent($challenge, ['sub' => 'attacker', 'email' => 'a@gmail.com', 'email_verified' => true, 'name' => 'A']);
        $this->client->getCookieJar()->clear();

        $response = $this->googleCallback(['state' => $state, 'code' => $code]);

        self::assertSame('invalid_state', $this->spaOutcome($response)['reason'] ?? null);
        $this->assertNoRefreshCookie($response);
        self::assertSame(0, $this->userRepository->count([]));
    }

    public function testStateMismatchIsRejected(): void
    {
        [, $challenge] = $this->startFlow();
        $code = FakeGoogleProvider::consent($challenge, ['sub' => 'g-1', 'email' => 'a@gmail.com', 'email_verified' => true, 'name' => 'A']);

        $response = $this->googleCallback(['state' => str_repeat('0', 64), 'code' => $code]);

        self::assertSame('invalid_state', $this->spaOutcome($response)['reason'] ?? null);
    }

    public function testCallbackCannotBeReplayed(): void
    {
        [$state, $challenge] = $this->startFlow();
        $flowCookie = $this->client->getCookieJar()->get('oauth_flow', '/api/auth/oauth', 'localhost');
        self::assertNotNull($flowCookie);
        $code = FakeGoogleProvider::consent($challenge, ['sub' => 'g-1', 'email' => 'a@gmail.com', 'email_verified' => true, 'name' => 'A']);
        self::assertSame('success', $this->spaOutcome($this->googleCallback(['state' => $state, 'code' => $code]))['status']);

        $this->client->getCookieJar()->set($flowCookie);
        $response = $this->googleCallback(['state' => $state, 'code' => $code]);

        self::assertSame('invalid_state', $this->spaOutcome($response)['reason'] ?? null);
    }

    public function testExpiredFlowIsRejected(): void
    {
        [$state, $challenge] = $this->startFlow();
        $code = FakeGoogleProvider::consent($challenge, ['sub' => 'g-1', 'email' => 'a@gmail.com', 'email_verified' => true, 'name' => 'A']);
        $this->setOnAllRows(OAuthFlow::class, 'expiresAt', new \DateTimeImmutable('-1 second'));

        self::assertSame('invalid_state', $this->spaOutcome($this->googleCallback(['state' => $state, 'code' => $code]))['reason'] ?? null);
    }

    /**
     * A code issued for another PKCE challenge (e.g. intercepted from another flow) can't be
     * redeemed with this flow's verifier.
     */
    public function testCodeBoundToAnotherPkceChallengeIsRejected(): void
    {
        [$state] = $this->startFlow();
        $code = FakeGoogleProvider::consent('some-other-challenge', ['sub' => 'g-1', 'email' => 'a@gmail.com', 'email_verified' => true, 'name' => 'A']);

        $response = $this->googleCallback(['state' => $state, 'code' => $code]);

        self::assertSame('provider_error', $this->spaOutcome($response)['reason'] ?? null);
        self::assertSame(0, $this->userRepository->count([]));
    }

    public function testUserCancellingAtGoogleIsReported(): void
    {
        [$state] = $this->startFlow();

        $response = $this->googleCallback(['state' => $state, 'error' => 'access_denied']);

        self::assertSame('cancelled', $this->spaOutcome($response)['reason'] ?? null);
    }

    public function testRedirectIsRateLimited(): void
    {
        for ($i = 0; $i < 30; ++$i) {
            $this->client->request('GET', '/api/auth/oauth/google/redirect');
        }

        $this->client->request('GET', '/api/auth/oauth/google/redirect');
        self::assertSame(429, $this->client->getResponse()->getStatusCode());
    }

    public function testUnsupportedProviderIs404(): void
    {
        $this->client->request('GET', '/api/auth/oauth/facebook/redirect');

        self::assertSame(404, $this->client->getResponse()->getStatusCode());
    }

    public function testSignedInUserLinksGoogleFromTheProfile(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        $response = $this->linkWithGoogle($accessToken, ['sub' => 'g-1', 'email' => 'alice.personal@gmail.com', 'email_verified' => true, 'name' => 'Alice']);

        self::assertSame(['status' => 'success', 'mode' => 'link', 'provider' => 'google'], $this->spaOutcome($response));
        $this->assertNoRefreshCookie($response);
        self::assertSame($user->getId()->toRfc4122(), $this->identity('g-1')->getUser()->getId()->toRfc4122());
        // The account's own email is untouched.
        self::assertSame('alice@example.com', $this->reloadUser($user)->getEmail());

        self::assertSame(1, $this->auditCount(AuditEventType::AccountLinked));
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('alice@example.com', $email->getTo()[0]->getAddress());
        self::assertStringContainsString('Google', (string) $email->getTextBody());

        // From now on, Google signs into this account.
        $this->client->getCookieJar()->clear();
        self::assertSame('success', $this->spaOutcome($this->loginWithGoogle(['sub' => 'g-1', 'email' => 'alice.personal@gmail.com', 'email_verified' => true, 'name' => 'Alice']))['status']);
        self::assertSame(1, $this->userRepository->count([]));
    }

    public function testLinkingRequiresAnAccessToken(): void
    {
        $this->client->request('POST', '/api/profile/identities/google/link', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testGoogleAccountAlreadyLinkedToSomeoneElseCannotBeLinked(): void
    {
        $this->loginWithGoogle(['sub' => 'g-1', 'email' => 'owner@gmail.com', 'email_verified' => true, 'name' => 'Owner']);
        $this->client->getCookieJar()->clear();
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        $response = $this->linkWithGoogle($accessToken, ['sub' => 'g-1', 'email' => 'owner@gmail.com', 'email_verified' => true, 'name' => 'Owner']);

        self::assertSame('identity_in_use', $this->spaOutcome($response)['reason'] ?? null);
        self::assertSame('owner@gmail.com', $this->identity('g-1')->getUser()->getEmail());
    }

    public function testSecondGoogleAccountCannotBeLinked(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);
        $this->linkWithGoogle($accessToken, ['sub' => 'g-1', 'email' => 'a1@gmail.com', 'email_verified' => true, 'name' => 'A']);

        $response = $this->linkWithGoogle($accessToken, ['sub' => 'g-2', 'email' => 'a2@gmail.com', 'email_verified' => true, 'name' => 'A']);

        self::assertSame('provider_already_linked', $this->spaOutcome($response)['reason'] ?? null);
    }

    public function testLinkingTheSameAccountTwiceIsANoOp(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);
        $this->linkWithGoogle($accessToken, ['sub' => 'g-1', 'email' => 'a1@gmail.com', 'email_verified' => true, 'name' => 'A']);

        $response = $this->linkWithGoogle($accessToken, ['sub' => 'g-1', 'email' => 'a1@gmail.com', 'email_verified' => true, 'name' => 'A']);

        self::assertSame('success', $this->spaOutcome($response)['status']);
        self::assertSame(1, $this->entityManager->getRepository(AuthIdentity::class)->count([]));
        self::assertSame(1, $this->auditCount(AuditEventType::AccountLinked));
    }

    public function testANewAccountNeedsAnInvitation(): void
    {
        $response = $this->loginWithGoogleUsing(null, ['sub' => 'g-1', 'email' => 'alice@gmail.com', 'email_verified' => true, 'name' => 'Alice']);

        self::assertSame(['status' => 'error', 'mode' => 'login', 'provider' => 'google', 'reason' => 'invitation_required'], $this->spaOutcome($response));
        $this->assertNoRefreshCookie($response);
        self::assertSame(0, $this->userRepository->count([]));
        self::assertSame(0, $this->entityManager->getRepository(AuthIdentity::class)->count([]));
    }

    public function testAReturningUserSignsInWithoutAnyInvitation(): void
    {
        $this->loginWithGoogle(['sub' => 'g-1', 'email' => 'alice@gmail.com', 'email_verified' => true, 'name' => 'Alice']);
        $this->client->getCookieJar()->clear();

        $response = $this->loginWithGoogleUsing(null, ['sub' => 'g-1', 'email' => 'alice@gmail.com', 'email_verified' => true, 'name' => 'Alice']);

        self::assertSame('success', $this->spaOutcome($response)['status']);
        self::assertSame(1, $this->userRepository->count([]));
    }

    public function testTheInvitationIsSpentOnTheNewAccount(): void
    {
        $key = $this->createInvitationKey();

        $response = $this->loginWithGoogleUsing($key, ['sub' => 'g-1', 'email' => 'alice@gmail.com', 'email_verified' => true, 'name' => 'Alice']);

        self::assertSame('success', $this->spaOutcome($response)['status']);
        $user = $this->identity('g-1')->getUser();
        $invitation = $this->findInvitation($key);
        self::assertSame(InvitationStatus::Used, $invitation->getStatus(new \DateTimeImmutable()));
        self::assertSame($user->getId()->toRfc4122(), $invitation->getUsedBy()?->getId()->toRfc4122());
        $log = $this->entityManager->getRepository(InvitationLog::class)->findOneBy(['invitation' => $invitation, 'action' => InvitationAction::KeyUsed]);
        self::assertInstanceOf(InvitationLog::class, $log);
        self::assertSame('google', $log->getDetails()['method'] ?? null);

        // The flow kept a ticket, never the key.
        self::assertSame(1, $this->entityManager->getRepository(OAuthFlow::class)->count(['registrationTicket' => hash('sha256', $key)]));
    }

    public function testAnInvalidInvitationIsRefusedBeforeGoingToGoogle(): void
    {
        $response = $this->postOAuthRedirect(AuthProvider::Google, str_repeat('A', 32));

        self::assertSame(['status' => 'error', 'mode' => 'login', 'provider' => 'google', 'reason' => 'invitation_invalid'], $this->spaOutcome($response));
        self::assertSame(0, $this->entityManager->getRepository(OAuthFlow::class)->count([]));
    }

    public function testAnExpiredInvitationIsRefusedBeforeGoingToGoogle(): void
    {
        $response = $this->postOAuthRedirect(AuthProvider::Google, $this->createInvitationKey(new \DateTimeImmutable('-1 minute')));

        self::assertSame('invitation_expired', $this->spaOutcome($response)['reason'] ?? null);
        self::assertSame(0, $this->entityManager->getRepository(OAuthFlow::class)->count([]));
    }

    public function testAnInvitationUsedMeanwhileIsRefusedAtTheCallback(): void
    {
        $key = $this->createInvitationKey();
        [$state, $challenge] = $this->startOAuthFlowWith(AuthProvider::Google, $key);
        $invitation = $this->findInvitation($key);
        $invitation->markUsed(new \DateTimeImmutable(), null);
        $this->entityManager->flush();

        $response = $this->googleCallback(['state' => $state, 'code' => FakeGoogleProvider::consent($challenge, ['sub' => 'g-1', 'email' => 'alice@gmail.com', 'email_verified' => true, 'name' => 'Alice'])]);

        self::assertSame('invitation_invalid', $this->spaOutcome($response)['reason'] ?? null);
        $this->assertNoRefreshCookie($response);
        self::assertSame(0, $this->userRepository->count([]));
    }

    /**
     * The person proved owning the address: telling them it has an account leaks nothing, and
     * their key stays usable.
     */
    public function testAnExistingAccountsEmailKeepsTheInvitation(): void
    {
        $this->createVerifiedUser('alice@gmail.com', self::PASSWORD);
        $key = $this->createInvitationKey();

        $response = $this->loginWithGoogleUsing($key, ['sub' => 'g-1', 'email' => 'alice@gmail.com', 'email_verified' => true, 'name' => 'Alice']);

        self::assertSame('account_exists', $this->spaOutcome($response)['reason'] ?? null);
        self::assertSame(InvitationStatus::Pending, $this->findInvitation($key)->getStatus(new \DateTimeImmutable()));
    }

    /**
     * @return array{string, string} state and PKCE challenge, as sent to Google
     */
    private function startFlow(): array
    {
        return $this->startOAuthFlow(AuthProvider::Google);
    }

    /**
     * @param array<string, string> $query
     */
    private function googleCallback(array $query): Response
    {
        return $this->oauthCallback(AuthProvider::Google, $query);
    }

    /**
     * @param array<string, mixed> $userInfo
     */
    private function loginWithGoogle(array $userInfo): Response
    {
        [$state, $challenge] = $this->startFlow();

        return $this->googleCallback(['state' => $state, 'code' => FakeGoogleProvider::consent($challenge, $userInfo)]);
    }

    /**
     * @param string|null          $invitationKey null: from the login page, without any key
     * @param array<string, mixed> $userInfo
     */
    private function loginWithGoogleUsing(?string $invitationKey, array $userInfo): Response
    {
        [$state, $challenge] = $this->startOAuthFlowWith(AuthProvider::Google, $invitationKey);

        return $this->googleCallback(['state' => $state, 'code' => FakeGoogleProvider::consent($challenge, $userInfo)]);
    }

    /**
     * @param array<string, mixed> $userInfo
     */
    private function linkWithGoogle(string $accessToken, array $userInfo): Response
    {
        [$state, $challenge] = $this->stateAndChallenge($this->startLink(AuthProvider::Google, $accessToken));

        return $this->googleCallback(['state' => $state, 'code' => FakeGoogleProvider::consent($challenge, $userInfo)]);
    }

    private function identity(string $googleId): AuthIdentity
    {
        return $this->findIdentity(AuthProvider::Google, $googleId);
    }
}
