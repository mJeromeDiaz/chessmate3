<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\EarlyAccess\InvitationLog;
use App\Enum\EarlyAccess\InvitationAction;
use App\Enum\EarlyAccess\InvitationStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;

final class RegisterControllerTest extends AuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';

    public function testNominalRegistrationCreatesUnverifiedUserAndQueuesEmail(): void
    {
        $key = $this->createInvitationKey();

        $response = $this->register('alice@example.com', self::PASSWORD, $key);

        self::assertSame(202, $response->getStatusCode());

        $user = $this->userRepository->findOneByEmail('alice@example.com');
        self::assertNotNull($user);
        self::assertFalse($user->isEmailVerified());

        self::assertQueuedEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertEmailAddressContains($message, 'To', 'alice@example.com');

        // The key is spent on this account (any address: it is not bound to the invited one).
        $invitation = $this->findInvitation($key);
        self::assertSame(InvitationStatus::Used, $invitation->getStatus(new \DateTimeImmutable()));
        self::assertSame($user->getId()->toRfc4122(), $invitation->getUsedBy()?->getId()->toRfc4122());
        $log = $this->entityManager->getRepository(InvitationLog::class)->findOneBy(['invitation' => $invitation, 'action' => InvitationAction::KeyUsed]);
        self::assertInstanceOf(InvitationLog::class, $log);
        self::assertSame($user->getId()->toRfc4122(), $log->getActor()?->getId()->toRfc4122());
        // assertEquals: MySQL's JSON type doesn't keep key order.
        self::assertEquals(['keyHint' => $invitation->getKeyHint(), 'method' => 'password', 'accountCreated' => true], $log->getDetails());
    }

    /**
     * The key is spent all the same: were it still usable, its holder would learn that the
     * address has an account.
     */
    public function testRegisteringAnAlreadyUsedEmailReturnsTheSameGenericResponseAndSpendsTheKey(): void
    {
        $this->createVerifiedUser('bob@example.com');
        $key = $this->createInvitationKey();

        $response = $this->register('bob@example.com', self::PASSWORD, $key);

        self::assertSame(202, $response->getStatusCode());
        self::assertQueuedEmailCount(0);
        $invitation = $this->findInvitation($key);
        self::assertSame(InvitationStatus::Used, $invitation->getStatus(new \DateTimeImmutable()));
        self::assertNull($invitation->getUsedBy());
        $log = $this->entityManager->getRepository(InvitationLog::class)->findOneBy(['invitation' => $invitation, 'action' => InvitationAction::KeyUsed]);
        self::assertInstanceOf(InvitationLog::class, $log);
        self::assertNull($log->getActor());
        self::assertFalse($log->getDetails()['accountCreated'] ?? null);

        self::assertSame('invitation_invalid', $this->decodeJson($this->register('bob2@example.com', self::PASSWORD, $key))['error'] ?? null);
    }

    public function testRegistrationWithoutAKeyIsRefused(): void
    {
        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'carol@example.com',
            'password' => self::PASSWORD,
        ], \JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invitation_required', $this->decodeJson($response)['error'] ?? null);
        self::assertNull($this->userRepository->findOneByEmail('carol@example.com'));
        self::assertQueuedEmailCount(0);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'malformed' => ['not-a-key'];
        yield 'unknown' => [str_repeat('A', 32)];
    }

    #[DataProvider('invalidKeys')]
    public function testAnInvalidKeyIsRefused(string $key): void
    {
        $response = $this->register('carol@example.com', self::PASSWORD, $key);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invitation_invalid', $this->decodeJson($response)['error'] ?? null);
        self::assertNull($this->userRepository->findOneByEmail('carol@example.com'));
    }

    public function testAKeyOpensOneAccountOnly(): void
    {
        $key = $this->createInvitationKey();
        self::assertSame(202, $this->register('first@example.com', self::PASSWORD, $key)->getStatusCode());

        $response = $this->register('second@example.com', self::PASSWORD, $key);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invitation_invalid', $this->decodeJson($response)['error'] ?? null);
        self::assertNull($this->userRepository->findOneByEmail('second@example.com'));
    }

    public function testARevokedKeyIsRefused(): void
    {
        $key = $this->createInvitationKey();
        $invitation = $this->findInvitation($key);
        $invitation->revoke(new \DateTimeImmutable());
        $this->entityManager->flush();

        $response = $this->register('carol@example.com', self::PASSWORD, $key);

        self::assertSame('invitation_invalid', $this->decodeJson($response)['error'] ?? null);
        self::assertNull($this->userRepository->findOneByEmail('carol@example.com'));
    }

    public function testAnExpiredKeyIsRefusedAndTheAttemptLogged(): void
    {
        $key = $this->createInvitationKey(new \DateTimeImmutable('-1 minute'));

        $response = $this->register('carol@example.com', self::PASSWORD, $key);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame('invitation_expired', $this->decodeJson($response)['error'] ?? null);
        self::assertNull($this->userRepository->findOneByEmail('carol@example.com'));
        $invitation = $this->findInvitation($key);
        self::assertSame(1, $this->entityManager->getRepository(InvitationLog::class)->count(['invitation' => $invitation, 'action' => InvitationAction::KeyExpired]));
        self::assertNull($invitation->getUsedAt());
    }

    public function testWeakPasswordIsRejected(): void
    {
        $key = $this->createInvitationKey();

        self::assertSame(422, $this->register('carol@example.com', 'short', $key)->getStatusCode());
        self::assertNull($this->userRepository->findOneByEmail('carol@example.com'));
        self::assertNull($this->findInvitation($key)->getUsedAt());
    }

    public function testRepeatedRegistrationAttemptsAreRateLimitedByIdentifier(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertNotSame(429, $this->register('dave@example.com', self::PASSWORD, $this->createInvitationKey())->getStatusCode());
        }

        self::assertSame(429, $this->register('dave@example.com', self::PASSWORD, $this->createInvitationKey())->getStatusCode());
    }

    private function register(string $email, string $password, string $invitationKey): Response
    {
        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => $password,
            'invitationKey' => $invitationKey,
        ], \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }
}
