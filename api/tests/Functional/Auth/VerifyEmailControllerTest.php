<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use SymfonyCasts\Bundle\VerifyEmail\VerifyEmailHelperInterface;

final class VerifyEmailControllerTest extends AuthWebTestCase
{
    public function testValidSignedLinkVerifiesTheAccountAndRedirects(): void
    {
        $user = $this->createUnverifiedUser('alice@example.com');

        /** @var VerifyEmailHelperInterface $helper */
        $helper = self::getContainer()->get(VerifyEmailHelperInterface::class);
        $signature = $helper->generateSignature(
            'app_verify_email',
            $user->getId()->toRfc4122(),
            (string) $user->getEmail(),
            ['id' => $user->getId()->toRfc4122()],
        );

        $path = parse_url($signature->getSignedUrl(), \PHP_URL_PATH).'?'.parse_url($signature->getSignedUrl(), \PHP_URL_QUERY);
        $this->client->request('GET', $path);

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('verified=1', (string) $this->client->getResponse()->headers->get('Location'));

        $reloaded = $this->userRepository->findOneByEmail('alice@example.com');
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->isEmailVerified());
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $user = $this->createUnverifiedUser('alice@example.com');

        $this->client->request('GET', '/api/auth/verify-email/'.$user->getId()->toRfc4122().'?expires=9999999999&signature=not-a-real-signature');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertStringContainsString('verified=0', (string) $this->client->getResponse()->headers->get('Location'));

        $reloaded = $this->userRepository->findOneByEmail('alice@example.com');
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isEmailVerified());
    }

    public function testResendIsIdenticalWhetherOrNotTheEmailIsRegistered(): void
    {
        $this->createUnverifiedUser('bob@example.com');

        $this->client->request('POST', '/api/auth/verify-email/resend', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'bob@example.com'], \JSON_THROW_ON_ERROR));
        $knownResponse = $this->client->getResponse();

        self::assertSame(202, $knownResponse->getStatusCode());
        self::assertQueuedEmailCount(1);
    }

    public function testResendForUnknownEmailReturnsTheSameGenericResponseWithoutSendingAnything(): void
    {
        $this->client->request('POST', '/api/auth/verify-email/resend', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['email' => 'nobody@example.com'], \JSON_THROW_ON_ERROR));

        self::assertSame(202, $this->client->getResponse()->getStatusCode());
        self::assertQueuedEmailCount(0);
    }
}
