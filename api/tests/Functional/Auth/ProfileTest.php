<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\AuthIdentity;
use App\Enum\AuditEventType;
use App\Enum\AuthProvider;
use App\Tests\Double\FakeGoogleProvider;
use App\Tests\Double\FakeLichessProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class ProfileTest extends OAuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';

    private const LICHESS_ACCOUNT = ['id' => 'magnus', 'username' => 'Magnus', 'perfs' => ['blitz' => ['games' => 10, 'rating' => 2000]]];

    private const GOOGLE_USER = ['sub' => 'g-1', 'email' => 'alice@gmail.com', 'email_verified' => true, 'name' => 'Alice'];

    protected function setUp(): void
    {
        parent::setUp();
        FakeGoogleProvider::reset();
        FakeLichessProvider::reset();
    }

    public function testProfileRequiresAnAccessToken(): void
    {
        $this->client->request('GET', '/api/profile', server: ['HTTP_ACCEPT' => 'application/json']);

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testProfileDescribesTheAccountWithoutAnySecret(): void
    {
        $accessToken = $this->signInWithLichess();

        $response = $this->profileRequest('GET', '/api/profile', $accessToken);

        self::assertSame(200, $response->getStatusCode());
        $profile = $this->decodeJson($response);
        self::assertNull($profile['email']);
        self::assertFalse($profile['hasPassword']);
        self::assertSame(['google'], $profile['linkableProviders']);
        self::assertIsArray($profile['identities']);
        self::assertCount(1, $profile['identities']);
        self::assertIsArray($profile['identities'][0]);
        self::assertSame('lichess', $profile['identities'][0]['provider']);
        self::assertSame('Magnus', $profile['identities'][0]['username']);
        self::assertFalse($profile['identities'][0]['removable']);

        $body = (string) $response->getContent();
        self::assertStringNotContainsString(FakeLichessProvider::$issuedTokens[0], $body);
        self::assertStringNotContainsString('v1:', $body);
        self::assertStringNotContainsStringIgnoringCase('tokenVersion', $body);
        self::assertStringNotContainsStringIgnoringCase('encrypted', $body);
    }

    public function testGoogleUserAddsAPasswordThatWorksWithEmailTwoFactor(): void
    {
        $accessToken = $this->signInWithGoogle();

        $response = $this->addPassword($accessToken, ['password' => self::PASSWORD]);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('added', $this->decodeJson($response)['status']);
        self::assertSame(1, $this->auditCount(AuditEventType::PasswordAdded));
        self::assertQueuedEmailCount(1);
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);
        self::assertSame('alice@gmail.com', $email->getTo()[0]->getAddress());

        // Password sign-in now works — through the emailed 2FA code.
        $this->client->getCookieJar()->clear();
        self::assertSame(200, $this->statusWithAccessToken($this->loginAndGetAccessToken('alice@gmail.com', self::PASSWORD)));
    }

    public function testAPasswordCannotBeAddedTwice(): void
    {
        $accessToken = $this->signInWithGoogle();
        $this->addPassword($accessToken, ['password' => self::PASSWORD]);

        $response = $this->addPassword($accessToken, ['password' => 'AnotherStrongPassphrase77!']);

        self::assertSame(409, $response->getStatusCode());
    }

    public function testWeakPasswordIsRejected(): void
    {
        $accessToken = $this->signInWithGoogle();

        self::assertSame(422, $this->addPassword($accessToken, ['password' => 'short'])->getStatusCode());
        self::assertSame(422, $this->addPassword($accessToken, ['password' => 'aaaaaaaaaaaaaaaa'])->getStatusCode());
    }

    public function testAccountWithoutEmailMustGiveOne(): void
    {
        $accessToken = $this->signInWithLichess();

        self::assertSame(422, $this->addPassword($accessToken, ['password' => self::PASSWORD])->getStatusCode());
        self::assertSame(422, $this->addPassword($accessToken, ['password' => self::PASSWORD, 'email' => 'not-an-email'])->getStatusCode());
    }

    public function testLichessUserAddsEmailAndPasswordUsableOnlyOnceTheEmailIsVerified(): void
    {
        $accessToken = $this->signInWithLichess();

        $response = $this->addPassword($accessToken, ['password' => self::PASSWORD, 'email' => 'Magnus@Example.com']);

        self::assertSame(202, $response->getStatusCode());
        $user = $this->findIdentity(AuthProvider::Lichess, 'magnus')->getUser();
        self::assertNull($user->getEmail());
        self::assertSame('magnus@example.com', $user->getPendingEmail());
        $verificationLink = $this->verificationLinkSentTo('magnus@example.com');

        // Not usable yet: the address isn't the account's email until confirmed.
        $this->client->getCookieJar()->clear();
        $this->postJson('/api/auth/login', ['email' => 'magnus@example.com', 'password' => self::PASSWORD]);
        self::assertArrayNotHasKey('mfaPendingToken', $this->decodeJson($this->client->getResponse()));

        $this->client->request('GET', $verificationLink);
        self::assertStringContainsString('verified=1', (string) $this->client->getResponse()->headers->get('Location'));

        $user = $this->reloadUser($user);
        self::assertSame('magnus@example.com', $user->getEmail());
        self::assertNull($user->getPendingEmail());
        self::assertTrue($user->isEmailVerified());
        self::assertSame(200, $this->statusWithAccessToken($this->loginAndGetAccessToken('magnus@example.com', self::PASSWORD)));
    }

    /**
     * An unconfirmed claim must not reserve the address: its real owner can still register, and
     * the claim then fails.
     */
    public function testPendingEmailDoesNotReserveTheAddress(): void
    {
        $accessToken = $this->signInWithLichess();
        $this->addPassword($accessToken, ['password' => self::PASSWORD, 'email' => 'victim@example.com']);
        $verificationLink = $this->verificationLinkSentTo('victim@example.com');

        $this->client->getCookieJar()->clear();
        $this->postJson('/api/auth/register', ['email' => 'victim@example.com', 'password' => 'VictimOwnPassphrase42!', 'invitationKey' => $this->createInvitationKey()]);
        self::assertNotNull($this->userRepository->findOneByEmail('victim@example.com'));

        $this->client->request('GET', $verificationLink);

        self::assertStringContainsString('verified=0', (string) $this->client->getResponse()->headers->get('Location'));
        self::assertNull($this->findIdentity(AuthProvider::Lichess, 'magnus')->getUser()->getEmail());
    }

    /**
     * Anti-enumeration: an address owned by another account gets the very same answer, nothing
     * changes, and its owner is told.
     */
    public function testEmailOfAnotherAccountIsNeitherRevealedNorTaken(): void
    {
        $this->createVerifiedUser('owner@example.com', self::PASSWORD);
        $accessToken = $this->signInWithLichess();

        $taken = $this->addPassword($accessToken, ['password' => self::PASSWORD, 'email' => 'owner@example.com']);

        // (The test mailer logger only holds the last request's messages.)
        $messages = self::getMailerMessages();
        self::assertCount(1, $messages);
        self::assertInstanceOf(Email::class, $messages[0]);
        self::assertSame('owner@example.com', $messages[0]->getTo()[0]->getAddress());
        self::assertStringNotContainsString('verify-email', (string) $messages[0]->getTextBody());
        self::assertNull($this->findIdentity(AuthProvider::Lichess, 'magnus')->getUser()->getPendingEmail());

        $free = $this->addPassword($accessToken, ['password' => self::PASSWORD, 'email' => 'free@example.com']);

        self::assertSame($free->getStatusCode(), $taken->getStatusCode());
        self::assertSame($free->getContent(), $taken->getContent());
        self::assertSame('free@example.com', $this->findIdentity(AuthProvider::Lichess, 'magnus')->getUser()->getPendingEmail());
    }

    public function testAddingAPasswordIsRateLimited(): void
    {
        $accessToken = $this->signInWithLichess();

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(202, $this->addPassword($accessToken, ['password' => self::PASSWORD, 'email' => "m{$i}@example.com"])->getStatusCode());
        }

        self::assertSame(429, $this->addPassword($accessToken, ['password' => self::PASSWORD, 'email' => 'm5@example.com'])->getStatusCode());
    }

    public function testUnlinkingRemovesTheIdentityRevokesItsTokenAndEndsOtherSessions(): void
    {
        $accessToken = $this->signInWithGoogle();
        $oldRefreshCookie = $this->client->getCookieJar()->get('refresh_token', '/api/auth', 'localhost');
        self::assertNotNull($oldRefreshCookie);
        $this->linkLichess($accessToken);
        $lichessIdentity = $this->findIdentity(AuthProvider::Lichess, 'magnus');

        $response = $this->profileRequest('DELETE', '/api/profile/identities/'.$lichessIdentity->getId()->toRfc4122(), $accessToken);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(0, $this->entityManager->getRepository(AuthIdentity::class)->count(['provider' => AuthProvider::Lichess]));
        self::assertSame(FakeLichessProvider::$issuedTokens, FakeLichessProvider::$revokedTokens);
        self::assertSame(1, $this->auditCount(AuditEventType::AccountUnlinked));

        // The caller continues in a new session...
        $body = $this->decodeJson($response);
        self::assertIsString($body['accessToken']);
        self::assertSame(200, $this->statusWithAccessToken($body['accessToken']));
        self::assertNotSame($oldRefreshCookie->getValue(), $this->extractCookieValue($response, 'refresh_token'));

        // ...every earlier one is over.
        self::assertSame(401, $this->statusWithAccessToken($accessToken));
        $this->setRefreshCookie($oldRefreshCookie->getValue());
        self::assertSame(401, $this->refresh()->getStatusCode());
    }

    public function testTheLastWayToSignInCannotBeRemoved(): void
    {
        $accessToken = $this->signInWithLichess();
        $identity = $this->findIdentity(AuthProvider::Lichess, 'magnus');

        $response = $this->profileRequest('DELETE', '/api/profile/identities/'.$identity->getId()->toRfc4122(), $accessToken);

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('last_auth_method', $this->decodeJson($response)['error']);
        self::assertSame(1, $this->entityManager->getRepository(AuthIdentity::class)->count([]));
        self::assertSame([], FakeLichessProvider::$revokedTokens);
    }

    /**
     * A password whose email isn't verified yet can't sign in, so it doesn't count.
     */
    public function testAPasswordAwaitingEmailVerificationDoesNotCountAsAWayToSignIn(): void
    {
        $accessToken = $this->signInWithLichess();
        $this->addPassword($accessToken, ['password' => self::PASSWORD, 'email' => 'magnus@example.com']);
        $identity = $this->findIdentity(AuthProvider::Lichess, 'magnus');

        self::assertSame(409, $this->profileRequest('DELETE', '/api/profile/identities/'.$identity->getId()->toRfc4122(), $accessToken)->getStatusCode());
    }

    public function testPasswordUserCanUnlinkTheirOnlyIdentity(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);
        $this->linkLichess($accessToken);
        $identity = $this->findIdentity(AuthProvider::Lichess, 'magnus');

        self::assertSame(200, $this->profileRequest('DELETE', '/api/profile/identities/'.$identity->getId()->toRfc4122(), $accessToken)->getStatusCode());
    }

    public function testIdentityOfAnotherUserCannotBeUnlinked(): void
    {
        $this->signInWithLichess();
        $victimIdentity = $this->findIdentity(AuthProvider::Lichess, 'magnus');
        $this->client->getCookieJar()->clear();
        $this->createVerifiedUser('mallory@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('mallory@example.com', self::PASSWORD);

        $response = $this->profileRequest('DELETE', '/api/profile/identities/'.$victimIdentity->getId()->toRfc4122(), $accessToken);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame(1, $this->entityManager->getRepository(AuthIdentity::class)->count([]));
        self::assertSame(404, $this->profileRequest('DELETE', '/api/profile/identities/not-a-uuid', $accessToken)->getStatusCode());
    }

    private function signInWithLichess(): string
    {
        [$state, $challenge] = $this->startOAuthFlow(AuthProvider::Lichess);
        $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => FakeLichessProvider::consent($challenge, self::LICHESS_ACCOUNT)]);

        return $this->accessTokenFromRefresh();
    }

    private function signInWithGoogle(): string
    {
        [$state, $challenge] = $this->startOAuthFlow(AuthProvider::Google);
        $this->oauthCallback(AuthProvider::Google, ['state' => $state, 'code' => FakeGoogleProvider::consent($challenge, self::GOOGLE_USER)]);

        return $this->accessTokenFromRefresh();
    }

    private function linkLichess(string $accessToken): void
    {
        [$state, $challenge] = $this->stateAndChallenge($this->startLink(AuthProvider::Lichess, $accessToken));
        $response = $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => FakeLichessProvider::consent($challenge, self::LICHESS_ACCOUNT)]);
        self::assertSame('success', $this->spaOutcome($response)['status']);
    }

    private function accessTokenFromRefresh(): string
    {
        $accessToken = $this->decodeJson($this->refresh())['accessToken'] ?? null;
        self::assertIsString($accessToken);

        return $accessToken;
    }

    /**
     * @param array<string, string> $data
     */
    private function addPassword(string $accessToken, array $data): Response
    {
        return $this->postJson('/api/profile/password', $data, ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken]);
    }

    private function profileRequest(string $method, string $uri, string $accessToken): Response
    {
        $this->client->request($method, $uri, server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken, 'HTTP_ACCEPT' => 'application/json']);

        return $this->client->getResponse();
    }

    /**
     * The path + query of the verification link in the last email sent to $address.
     */
    private function verificationLinkSentTo(string $address): string
    {
        $messages = array_values(array_filter(
            self::getMailerMessages(),
            static fn ($message): bool => $message instanceof Email && $message->getTo()[0]->getAddress() === $address,
        ));
        $message = end($messages);
        self::assertInstanceOf(Email::class, $message);

        if (1 !== preg_match('#https?://[^/\s]+(/api/auth/verify-email/\S+)#', (string) $message->getTextBody(), $matches)) {
            self::fail('No verification link in the email.');
        }

        return $matches[1];
    }
}
