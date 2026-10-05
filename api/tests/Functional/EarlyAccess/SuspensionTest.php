<?php

declare(strict_types=1);

namespace App\Tests\Functional\EarlyAccess;

use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\AuthProvider;
use App\Tests\Double\FakeLichessProvider;
use App\Tests\Functional\Auth\OAuthWebTestCase;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Component\HttpFoundation\Response;

/**
 * Account suspension (docs/EARLY_ACCESS.md): POST /api/admin/users/{id}/suspend and /unsuspend,
 * and every way into a session refused meanwhile.
 */
final class SuspensionTest extends OAuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';
    private const LICHESS_ACCOUNT = ['id' => 'magnus', 'username' => 'Magnus', 'perfs' => ['blitz' => ['games' => 10, 'rating' => 2000]]];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        FakeLichessProvider::reset();
        $this->admin = $this->createVerifiedUser('admin@example.com', self::PASSWORD);
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->userRepository->save($this->admin);
    }

    public function testSuspendingClosesEverySessionAtOnce(): void
    {
        $alice = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD, trustDevice: true);
        self::assertSame(200, $this->statusWithAccessToken($accessToken));

        $response = $this->admin('POST', '/api/admin/users/'.$alice->getId()->toRfc4122().'/suspend', ['reason' => '  Triche répétée  ']);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $player = $this->decodeJson($response);
        self::assertIsString($player['suspendedAt']);
        self::assertSame('Triche répétée', $player['suspensionReason']);
        // The access token already issued, and the refresh cookie, stop working right away.
        self::assertSame(401, $this->statusWithAccessToken($accessToken));
        self::assertSame(401, $this->refresh()->getStatusCode());
        self::assertSame(1, $this->auditCount(AuditEventType::AccountSuspended));
    }

    /**
     * Told only once the password is proven, and before any email code is sent: the trusted device
     * (revoked anyway) does not get around it.
     */
    public function testASuspendedPlayerIsToldOnlyWithTheRightPassword(): void
    {
        $alice = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD, trustDevice: true);
        $this->suspend($alice);

        self::assertSame(401, $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => 'wrong-password-123'])->getStatusCode());
        $response = $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => self::PASSWORD]);

        self::assertSame(423, $response->getStatusCode());
        self::assertEmailCount(0);
        $this->assertNoRefreshCookie($response);
    }

    public function testALoginWaitingForItsEmailCodeCannotFinish(): void
    {
        $alice = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $code = $this->extractMfaCode();

        $this->suspend($alice);
        $response = $this->submitMfaCode($pendingToken, $code);

        self::assertNotSame(200, $response->getStatusCode());
        $this->assertNoRefreshCookie($response);
    }

    public function testASuspendedLichessAccountCannotSignIn(): void
    {
        [$state, $challenge] = $this->startOAuthFlow(AuthProvider::Lichess);
        $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => FakeLichessProvider::consent($challenge, self::LICHESS_ACCOUNT)]);
        $this->client->getCookieJar()->clear();
        $this->suspend($this->findIdentity(AuthProvider::Lichess, 'magnus')->getUser());

        [$state, $challenge] = $this->startOAuthFlowWith(AuthProvider::Lichess, null);
        $response = $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => FakeLichessProvider::consent($challenge, self::LICHESS_ACCOUNT)]);

        self::assertSame(['status' => 'error', 'mode' => 'login', 'provider' => 'lichess', 'reason' => 'account_suspended'], $this->spaOutcome($response));
        $this->assertNoRefreshCookie($response);
    }

    public function testLiftingTheSuspensionLetsThePlayerSignInAgain(): void
    {
        $alice = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->suspend($alice);

        $response = $this->admin('POST', '/api/admin/users/'.$alice->getId()->toRfc4122().'/unsuspend');

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $player = $this->decodeJson($response);
        self::assertNull($player['suspendedAt']);
        self::assertNull($player['suspensionReason']);
        self::assertSame(200, $this->statusWithAccessToken($this->loginAndGetAccessToken('alice@example.com', self::PASSWORD)));
        self::assertSame(1, $this->auditCount(AuditEventType::AccountUnsuspended));
    }

    public function testBothActionsAreIdempotent(): void
    {
        $alice = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $uri = '/api/admin/users/'.$alice->getId()->toRfc4122();

        self::assertSame(200, $this->admin('POST', $uri.'/suspend', ['reason' => 'first'])->getStatusCode());
        $first = $this->reloadUser($alice);
        $response = $this->admin('POST', $uri.'/suspend', ['reason' => 'second']);

        self::assertSame(200, $response->getStatusCode());
        $again = $this->reloadUser($alice);
        // Same date, same token version: only the note changed.
        self::assertEquals($first->getSuspendedAt(), $again->getSuspendedAt());
        self::assertSame($first->getTokenVersion(), $again->getTokenVersion());
        self::assertSame('second', $again->getSuspensionReason());

        $lifted = $this->admin('POST', $uri.'/unsuspend');
        self::assertSame(200, $lifted->getStatusCode());
        $liftedAgain = $this->admin('POST', $uri.'/unsuspend');
        self::assertSame(200, $liftedAgain->getStatusCode());
        self::assertSame(1, $this->auditCount(AuditEventType::AccountUnsuspended));
    }

    public function testRefusals(): void
    {
        $other = $this->createVerifiedUser('other-admin@example.com', self::PASSWORD);
        $other->setRoles(['ROLE_ADMIN']);
        $this->userRepository->save($other);
        $player = $this->createVerifiedUser('player@example.com', self::PASSWORD);

        self::assertSame(409, $this->admin('POST', '/api/admin/users/'.$this->admin->getId()->toRfc4122().'/suspend')->getStatusCode());
        self::assertSame(409, $this->admin('POST', '/api/admin/users/'.$other->getId()->toRfc4122().'/suspend')->getStatusCode());
        self::assertSame(404, $this->admin('POST', '/api/admin/users/0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b/suspend')->getStatusCode());
        self::assertSame(422, $this->admin('POST', '/api/admin/users/'.$player->getId()->toRfc4122().'/suspend', ['reason' => str_repeat('x', 501)])->getStatusCode());
        self::assertFalse($this->reloadUser($player)->isSuspended());

        $uri = '/api/admin/users/'.$player->getId()->toRfc4122().'/suspend';
        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json'], content: '{}');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/ld+json', 'HTTP_ACCEPT' => 'application/ld+json', 'HTTP_AUTHORIZATION' => 'Bearer '.$this->token($this->reloadUser($player))], content: '{}');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testThePlayerListShowsAndFiltersSuspensions(): void
    {
        $alice = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $this->createVerifiedUser('bob@example.com', self::PASSWORD);
        $this->suspend($alice);

        $suspended = $this->decodeJson($this->admin('GET', '/api/admin/users?suspended=true'));
        $others = $this->decodeJson($this->admin('GET', '/api/admin/users?suspended=false'));

        self::assertIsArray($suspended['member'] ?? null);
        self::assertSame(['alice@example.com'], array_column($suspended['member'], 'email'));
        self::assertIsArray($others['member'] ?? null);
        self::assertSame(['bob@example.com', 'admin@example.com'], array_column($others['member'], 'email'));
    }

    private function suspend(User $user): void
    {
        $response = $this->admin('POST', '/api/admin/users/'.$user->getId()->toRfc4122().'/suspend', ['reason' => 'test']);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    /**
     * @param array<string, mixed> $body
     */
    private function admin(string $method, string $uri, array $body = []): Response
    {
        $this->client->request($method, $uri, server: [
            'CONTENT_TYPE' => 'application/ld+json',
            'HTTP_ACCEPT' => 'application/ld+json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->token($this->reloadUser($this->admin)),
        ], content: 'GET' === $method ? null : json_encode((object) $body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    private function token(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }
}
