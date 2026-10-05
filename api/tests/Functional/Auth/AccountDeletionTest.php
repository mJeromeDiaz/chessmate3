<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Enum\AuthProvider;
use App\Enum\Repertoire\Color;
use App\Repertoire\RepertoireManager;
use App\Security\OAuth\OAuthTokenVault;
use App\Tests\Double\FakeLichessProvider;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

/**
 * Account deletion (docs/AUTH.md): the emailed code or a recent sign-in, the frozen account, the
 * cancellation, the purge.
 */
final class AccountDeletionTest extends OAuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';
    private const LICHESS_ACCOUNT = ['id' => 'magnus', 'username' => 'Magnus', 'perfs' => ['blitz' => ['games' => 10, 'rating' => 2000]]];

    protected function setUp(): void
    {
        parent::setUp();
        FakeLichessProvider::reset();
    }

    public function testTheEmailedCodeFreezesTheAccountUntilItIsCancelled(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->signIn('alice@example.com');

        $response = $this->call('POST', '/api/auth/account-deletion', $accessToken);
        self::assertSame(202, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('email', $this->decodeJson($response)['method']);
        $code = $this->deletionCode();
        $wrong = '000000' === $code ? '111111' : '000000';

        $response = $this->call('POST', '/api/auth/account-deletion/confirm', $accessToken, ['code' => $wrong]);
        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invalid_code', $this->decodeJson($response)['error']);

        $response = $this->call('POST', '/api/auth/account-deletion/confirm', $accessToken, ['code' => $code]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $scheduledFor = $this->decodeJson($response)['deletionScheduledAt'] ?? null;
        self::assertIsString($scheduledFor);
        $scheduledAt = new \DateTimeImmutable($scheduledFor);
        self::assertEqualsWithDelta((new \DateTimeImmutable('+30 days'))->getTimestamp(), $scheduledAt->getTimestamp(), 60);
        self::assertSame('', $this->extractCookieValue($response, 'refresh_token'), 'refresh cookie cleared');
        self::assertSame(1, $this->auditCount(AuditEventType::AccountDeletionScheduled));

        // Signed out everywhere.
        self::assertSame(401, $this->statusWithAccessToken($accessToken));
        self::assertSame(401, $this->refresh()->getStatusCode());

        // Signing in again: the profile, the cancellation, nothing else.
        $accessToken = $this->signIn('alice@example.com');
        $profile = $this->decodeJson($this->call('GET', '/api/profile', $accessToken));
        self::assertSame($scheduledAt->format(\DATE_ATOM), $profile['deletionScheduledAt']);
        $response = $this->call('GET', '/api/profile/trusted-devices', $accessToken);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('account_frozen', $this->decodeJson($response)['error']);
        self::assertSame(403, $this->call('GET', '/api/puzzles/rating', $accessToken)->getStatusCode());
        self::assertSame(409, $this->call('POST', '/api/auth/account-deletion', $accessToken)->getStatusCode(), 'already scheduled');

        self::assertSame(204, $this->call('POST', '/api/auth/account-deletion/cancel', $accessToken)->getStatusCode());
        self::assertSame(1, $this->auditCount(AuditEventType::AccountDeletionCancelled));
        self::assertSame(200, $this->call('GET', '/api/profile/trusted-devices', $accessToken)->getStatusCode());
        self::assertNull($this->decodeJson($this->call('GET', '/api/profile', $accessToken))['deletionScheduledAt']);
        self::assertSame(204, $this->call('POST', '/api/auth/account-deletion/cancel', $accessToken)->getStatusCode(), 'idempotent');
    }

    public function testACodeAllowsFiveAttemptsTenMinutesAndIsNotResentTooSoon(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->signIn('alice@example.com');

        $this->call('POST', '/api/auth/account-deletion', $accessToken);
        $code = $this->deletionCode();
        $wrong = '000000' === $code ? '111111' : '000000';
        $response = $this->call('POST', '/api/auth/account-deletion', $accessToken);
        self::assertSame(429, $response->getStatusCode());
        self::assertSame('resend_too_soon', $this->decodeJson($response)['error']);

        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(422, $this->call('POST', '/api/auth/account-deletion/confirm', $accessToken, ['code' => $wrong])->getStatusCode());
        }
        $response = $this->call('POST', '/api/auth/account-deletion/confirm', $accessToken, ['code' => $code]);
        self::assertSame(410, $response->getStatusCode(), 'out of attempts, even with the right code');
        self::assertSame('code_expired', $this->decodeJson($response)['error']);

        // A new code, then expired.
        $this->connection()->executeStatement('UPDATE account_deletion_code SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE)');
        self::assertSame(202, $this->call('POST', '/api/auth/account-deletion', $accessToken)->getStatusCode());
        $code = $this->deletionCode();
        $this->connection()->executeStatement('UPDATE account_deletion_code SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)');
        self::assertSame(410, $this->call('POST', '/api/auth/account-deletion/confirm', $accessToken, ['code' => $code])->getStatusCode());

        self::assertSame(422, $this->call('POST', '/api/auth/account-deletion/confirm', $accessToken, ['code' => '12ab'])->getStatusCode(), 'six digits');
        self::assertNull($this->reloadUser($this->user('alice@example.com'))->getDeletionScheduledAt());
    }

    public function testAnAccountWithoutEmailConfirmsWithARecentSignIn(): void
    {
        $accessToken = $this->signInWithLichess();

        $response = $this->call('POST', '/api/auth/account-deletion', $accessToken);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(['method' => 'recent_sign_in', 'recentSignIn' => true], $this->decodeJson($response));
        self::assertEmpty(self::getMailerMessages(), 'no code without an email');

        // Signed in an hour ago: sign in again first.
        $this->connection()->executeStatement('UPDATE refresh_token SET signed_in_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 HOUR)');
        self::assertFalse($this->decodeJson($this->call('POST', '/api/auth/account-deletion', $accessToken))['recentSignIn']);
        $response = $this->call('POST', '/api/auth/account-deletion/confirm', $accessToken, []);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('recent_sign_in_required', $this->decodeJson($response)['error']);

        $accessToken = $this->signInWithLichess();
        $response = $this->call('POST', '/api/auth/account-deletion/confirm', $accessToken, []);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame(403, $this->call('GET', '/api/profile/trusted-devices', $this->signInWithLichess())->getStatusCode());
    }

    public function testThePurgeDeletesTheAccountRevokesLichessAndAnonymizesTheJournal(): void
    {
        // One kernel for the whole test: the services fetched below share the test's entity manager.
        $this->client->disableReboot();
        $alice = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $bob = $this->createVerifiedUser('bob@example.com', self::PASSWORD);
        $this->signIn('alice@example.com'); // audit entries with an IP, a refresh token
        $this->signIn('bob@example.com');
        // The requests above went through the kernel: work on a managed copy.
        $alice = $this->reloadUser($alice);
        $identity = new AuthIdentity($alice, AuthProvider::Lichess, 'magnus');
        self::getContainer()->get(OAuthTokenVault::class)->store($identity, 'lichess-token');
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
        self::getContainer()->get(RepertoireManager::class)->create($alice, 'Italienne', Color::White);
        $alice = $this->reloadUser($alice);
        $aliceId = $alice->getId();
        $alice->scheduleDeletion(new \DateTimeImmutable('-1 minute'));
        $this->entityManager->flush();
        $notDue = $this->reloadUser($bob)->scheduleDeletion(new \DateTimeImmutable('+1 day'));
        $this->entityManager->flush();

        self::assertNotNull(self::$kernel);
        $command = new CommandTester((new Application(self::$kernel))->find('app:account:purge'));
        self::assertSame(0, $command->execute([]));
        self::assertStringContainsString('1 account(s) purged.', $command->getDisplay());

        $this->entityManager->clear();
        self::assertNull($this->userRepository->find($aliceId));
        self::assertNotNull($this->userRepository->find($notDue->getId()), 'not due yet');
        self::assertSame(['lichess-token'], FakeLichessProvider::$revokedTokens);
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM refresh_token WHERE username = ?', [$aliceId->toRfc4122()]));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM repertoire WHERE user_id = ?', [$aliceId->toBinary()]));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM audit_log_entry WHERE user_id IS NULL AND (ip IS NOT NULL OR user_agent IS NOT NULL)'), 'the journal keeps no IP');
        self::assertGreaterThan(0, $this->countRows("SELECT COUNT(*) FROM audit_log_entry WHERE user_id IS NULL AND event_type = 'login_success'"), 'the rows are kept');
        self::assertSame(1, $this->auditCount(AuditEventType::AccountDeleted));

        $command->execute([]);
        self::assertStringContainsString('0 account(s) purged.', $command->getDisplay(), 'idempotent');
    }

    /**
     * Signs in with the password and the emailed 2FA code; the client keeps the refresh cookie.
     *
     * @return string the access token
     */
    private function signIn(string $email): string
    {
        $this->loginAndGetRefreshCookie($email, self::PASSWORD);
        $accessToken = $this->decodeJson($this->client->getResponse())['accessToken'] ?? null;
        self::assertIsString($accessToken);

        return $accessToken;
    }

    private function signInWithLichess(): string
    {
        [$state, $challenge] = $this->startOAuthFlow(AuthProvider::Lichess);
        $this->oauthCallback(AuthProvider::Lichess, ['state' => $state, 'code' => FakeLichessProvider::consent($challenge, self::LICHESS_ACCOUNT)]);
        $accessToken = $this->decodeJson($this->refresh())['accessToken'] ?? null;
        self::assertIsString($accessToken);

        return $accessToken;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $uri, string $accessToken, ?array $body = null): Response
    {
        $this->client->request($method, $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
            'HTTP_ACCEPT' => 'application/json',
            'CONTENT_TYPE' => 'application/json',
        ], content: null === $body ? null : json_encode((object) $body, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    /** The code of the last deletion email. */
    private function deletionCode(): string
    {
        $messages = array_values(array_filter(
            self::getMailerMessages(),
            static fn ($message): bool => $message instanceof Email && str_starts_with((string) $message->getSubject(), 'Confirmez la suppression'),
        ));
        $message = end($messages);
        self::assertInstanceOf(Email::class, $message, 'No deletion code was emailed.');
        if (1 !== preg_match('/\b(\d{6})\b/', (string) $message->getTextBody(), $matches)) {
            self::fail('No 6-digit code in the email.');
        }

        return $matches[1];
    }

    private function user(string $email): User
    {
        $user = $this->userRepository->findOneBy(['email' => $email]);
        self::assertInstanceOf(User::class, $user);

        return $user;
    }

    /**
     * A COUNT(*) query's result.
     *
     * @param list<mixed> $params
     */
    private function countRows(string $sql, array $params = []): int
    {
        $count = $this->connection()->fetchOne($sql, $params);
        self::assertTrue(is_numeric($count));

        return (int) $count;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
