<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use Symfony\Component\Mime\Email;

final class RegisterControllerTest extends AuthWebTestCase
{
    public function testNominalRegistrationCreatesUnverifiedUserAndQueuesEmail(): void
    {
        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'alice@example.com',
            'password' => 'CorrectHorseBatteryStaple9!',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        $user = $this->userRepository->findOneByEmail('alice@example.com');
        self::assertNotNull($user);
        self::assertFalse($user->isEmailVerified());

        self::assertQueuedEmailCount(1);
        $message = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $message);
        self::assertEmailAddressContains($message, 'To', 'alice@example.com');
    }

    public function testRegisteringAnAlreadyUsedEmailReturnsTheSameGenericResponse(): void
    {
        $this->createVerifiedUser('bob@example.com');

        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'bob@example.com',
            'password' => 'CorrectHorseBatteryStaple9!',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(202, $this->client->getResponse()->getStatusCode());
        self::assertQueuedEmailCount(0);
    }

    public function testWeakPasswordIsRejected(): void
    {
        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'carol@example.com',
            'password' => 'short',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        self::assertNull($this->userRepository->findOneByEmail('carol@example.com'));
    }

    public function testRepeatedRegistrationAttemptsAreRateLimitedByIdentifier(): void
    {
        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'dave@example.com',
                'password' => 'CorrectHorseBatteryStaple9!',
            ], \JSON_THROW_ON_ERROR));
        }

        self::assertSame(202, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/auth/register', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'dave@example.com',
            'password' => 'CorrectHorseBatteryStaple9!',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(429, $this->client->getResponse()->getStatusCode());
    }
}
