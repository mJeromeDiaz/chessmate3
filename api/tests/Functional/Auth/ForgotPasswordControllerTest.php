<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Entity\AuditLogEntry;
use App\Entity\ResetPasswordRequest;
use App\Entity\User;
use App\Enum\AuditEventType;
use App\Security\Password\Message\PasswordResetRequested;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

final class ForgotPasswordControllerTest extends AuthWebTestCase
{
    public function testKnownEmailReceivesASingleUseResetLink(): void
    {
        $this->createVerifiedUser('alice@example.com');

        $response = $this->requestPasswordReset('Alice@Example.COM');

        self::assertSame(202, $response->getStatusCode());
        self::assertQueuedEmailCount(1);
        $body = $this->lastEmailText();
        self::assertMatchesRegularExpression('~http://localhost:9000/#/reset-password\?token=[A-Za-z0-9]{40}\b~', $body);
        self::assertStringContainsString('30 minutes', $body);

        // Only the selector is stored in clear, the verifier half is not.
        if (1 !== preg_match('~token=([A-Za-z0-9]{40})~', $body, $matches)) {
            self::fail('No reset token found in the email.');
        }
        $token = $matches[1];
        $repository = $this->entityManager->getRepository(ResetPasswordRequest::class);
        self::assertSame(1, $repository->count(['selector' => substr($token, 0, 20)]));
        $request = $repository->findOneBy([]);
        self::assertInstanceOf(ResetPasswordRequest::class, $request);
        self::assertStringNotContainsString(substr($token, 20), $request->getHashedToken());

        self::assertSame(['link_sent'], $this->auditOutcomes());
    }

    public function testUnknownEmailGetsTheExactSameResponseAndNoEmail(): void
    {
        $this->createVerifiedUser('alice@example.com');

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'alice@example.com']);
        $unknown = $this->requestPasswordReset('nobody@example.com');

        self::assertQueuedEmailCount(0);
        self::assertSame($known->getStatusCode(), $unknown->getStatusCode());
        self::assertSame($known->getContent(), $unknown->getContent());
    }

    public function testTheRequestItselfOnlyQueuesAMessageAndNeverLooksTheEmailUp(): void
    {
        $this->createVerifiedUser('alice@example.com');

        $this->postJson('/api/auth/forgot-password', ['email' => 'alice@example.com']);

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(PasswordResetRequested::class, $message);
        self::assertSame('alice@example.com', $message->email);

        // Nothing account-dependent happened during the request.
        self::assertSame(0, $this->entityManager->getRepository(ResetPasswordRequest::class)->count([]));
        self::assertSame([], $this->auditOutcomes());
    }

    public function testAccountWithoutPasswordGetsAnExplanationButNoLink(): void
    {
        $this->createVerifiedUser('alice@example.com');
        $this->setOnAllRows(User::class, 'password', null);

        $response = $this->requestPasswordReset('alice@example.com');

        self::assertSame(202, $response->getStatusCode());
        self::assertQueuedEmailCount(1);
        $body = $this->lastEmailText();
        self::assertStringContainsString("n'a pas de mot de passe", $body);
        self::assertStringNotContainsString('token=', $body);
        self::assertSame(0, $this->entityManager->getRepository(ResetPasswordRequest::class)->count([]));
    }

    public function testASecondRequestWithinFiveMinutesSendsNothing(): void
    {
        $this->createVerifiedUser('alice@example.com');
        $this->requestPasswordReset('alice@example.com');

        $response = $this->requestPasswordReset('alice@example.com');

        self::assertSame(202, $response->getStatusCode());
        self::assertQueuedEmailCount(0);
        self::assertSame(['link_sent', 'throttled'], $this->auditOutcomes());

        // Once the throttle window has passed, a new link is sent.
        $this->setOnAllRows(ResetPasswordRequest::class, 'requestedAt', new \DateTimeImmutable('-6 minutes'));
        $this->requestPasswordReset('alice@example.com');
        self::assertQueuedEmailCount(1);
    }

    public function testRepeatedRequestsForTheSameEmailAreRateLimited(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(202, $this->postJson('/api/auth/forgot-password', ['email' => 'alice@example.com'])->getStatusCode());
        }

        self::assertSame(429, $this->postJson('/api/auth/forgot-password', ['email' => 'alice@example.com'])->getStatusCode());
    }

    public function testInvalidEmailIsRejected(): void
    {
        self::assertSame(422, $this->postJson('/api/auth/forgot-password', ['email' => 'not-an-email'])->getStatusCode());
    }

    private function lastEmailText(): string
    {
        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        return (string) $email->getTextBody();
    }

    /**
     * @return list<mixed>
     */
    private function auditOutcomes(): array
    {
        $entries = $this->entityManager->getRepository(AuditLogEntry::class)->findBy(['eventType' => AuditEventType::PasswordResetRequested], ['createdAt' => 'ASC']);

        return array_map(static fn (AuditLogEntry $entry): mixed => $entry->getMetadata()['outcome'] ?? null, $entries);
    }
}
