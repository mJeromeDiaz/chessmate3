<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

final class LoginControllerTest extends AuthWebTestCase
{
    public function testValidCredentialsStartA2faChallengeWithoutIssuingAnyJwt(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');

        $response = $this->postJson('/api/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'CorrectHorseBatteryStaple9!',
        ]);

        self::assertSame(202, $response->getStatusCode());

        $body = $this->decodeJson($response);
        self::assertIsString($body['mfaPendingToken'] ?? null);
        self::assertSame(64, \strlen($body['mfaPendingToken']));
        self::assertSame('email', $body['method'] ?? null);
        self::assertArrayNotHasKey('accessToken', $body);
        self::assertSame([], $response->headers->getCookies(), 'No session cookie may be set before the code is verified.');

        self::assertEmailCount(1);
    }

    public function testCodeEmailTellsTheUserWhenAndFromWhereItWasRequested(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');

        $this->postJson('/api/auth/login', [
            'email' => 'alice@example.com',
            'password' => 'CorrectHorseBatteryStaple9!',
        ], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0 Safari/537.36']);

        $email = self::getMailerMessage();
        self::assertNotNull($email);
        self::assertEmailAddressContains($email, 'To', 'alice@example.com');
        self::assertEmailTextBodyContains($email, 'Chrome on Windows');
        self::assertEmailTextBodyContains($email, (new \DateTimeImmutable())->format('d/m/Y'));
        self::assertEmailTextBodyContains($email, "Si ce n'est pas vous, changez votre mot de passe");
        self::assertEmailHtmlBodyContains($email, 'Chrome on Windows');
    }

    public function testFailedLoginDoesNotSendAnyCode(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');

        $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => 'WrongPassword123!']);

        self::assertEmailCount(0);
    }

    public function testWrongPasswordIsRejectedWithGenericMessage(): void
    {
        $this->createVerifiedUser('alice@example.com', 'CorrectHorseBatteryStaple9!');

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'alice@example.com',
            'password' => 'WrongPassword123!',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testUnknownEmailIsRejectedWithTheSameStatusAsAWrongPassword(): void
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'nobody@example.com',
            'password' => 'WrongPassword123!',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testUnverifiedEmailIsRejectedAfterCredentialsAreConfirmedValid(): void
    {
        $this->createUnverifiedUser('carol@example.com', 'CorrectHorseBatteryStaple9!');

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'carol@example.com',
            'password' => 'CorrectHorseBatteryStaple9!',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testRepeatedFailedLoginsAreRateLimitedByIdentifier(): void
    {
        $this->createVerifiedUser('dave@example.com', 'CorrectHorseBatteryStaple9!');

        for ($i = 0; $i < 5; ++$i) {
            $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
                'email' => 'dave@example.com',
                'password' => 'WrongPassword123!',
            ], \JSON_THROW_ON_ERROR));
        }

        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => 'dave@example.com',
            // Even the correct password must now be blocked by the rate limiter.
            'password' => 'CorrectHorseBatteryStaple9!',
        ], \JSON_THROW_ON_ERROR));

        self::assertSame(429, $this->client->getResponse()->getStatusCode());
    }
}
