<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\EarlyAccess\Invitation\KeyGenerator;
use App\Entity\EarlyAccess\InvitationKey;
use App\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\MailerAssertionsTrait;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Mime\Email;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

abstract class AuthWebTestCase extends WebTestCase
{
    use MailerAssertionsTrait;

    protected KernelBrowser $client;
    protected EntityManagerInterface $entityManager;
    protected UserRepository $userRepository;
    protected UserPasswordHasherInterface $passwordHasher;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $this->userRepository = self::getContainer()->get(UserRepository::class);
        $this->passwordHasher = self::getContainer()->get(UserPasswordHasherInterface::class);

        // Rate limiter state lives in a filesystem-backed cache pool (no Redis in this project), so
        // — unlike the database, which DAMA rolls back per test — it survives across test methods
        // unless cleared explicitly here.
        self::getContainer()->get('cache.rate_limiter')->clear();
    }

    protected function createVerifiedUser(string $email, string $plainPassword = 'a-strong-passw0rd!'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $user->markEmailVerified();
        $this->userRepository->save($user);

        return $user;
    }

    /**
     * A pending early access key (docs/EARLY_ACCESS.md), as an admin would create it: new accounts
     * need one.
     *
     * @param \DateTimeImmutable|null $expiresAt null: never expires
     */
    protected function createInvitationKey(?\DateTimeImmutable $expiresAt = null, string $email = 'guest@example.com'): string
    {
        $key = (new KeyGenerator())->generate();
        $this->entityManager->persist(new InvitationKey($email, KeyGenerator::hash($key), KeyGenerator::hint($key), null, new \DateTimeImmutable(), $expiresAt));
        $this->entityManager->flush();

        return $key;
    }

    protected function findInvitation(string $key): InvitationKey
    {
        $this->entityManager->clear();
        $invitation = $this->entityManager->getRepository(InvitationKey::class)->findOneBy(['keyHash' => KeyGenerator::hash($key)]);
        self::assertInstanceOf(InvitationKey::class, $invitation);

        return $invitation;
    }

    protected function createUnverifiedUser(string $email, string $plainPassword = 'a-strong-passw0rd!'): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->setPassword($this->passwordHasher->hashPassword($user, $plainPassword));
        $this->userRepository->save($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }

    /**
     * Reads a cookie's raw value straight off the response, independently of the test client's
     * cookie jar — so a test can capture an old value and replay it after the jar has moved on.
     */
    protected function extractCookieValue(Response $response, string $name): string
    {
        return (string) $this->findResponseCookie($response, $name)->getValue();
    }

    protected function findResponseCookie(Response $response, string $name): \Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($name === $cookie->getName()) {
                return $cookie;
            }
        }

        self::fail(sprintf('Cookie "%s" not found on response.', $name));
    }

    /**
     * Forgot-password as it runs in production: the request only queues a message on the async
     * transport, then a worker handles it — simulated here by running the handler directly on what
     * was queued. Returns the HTTP response of the request itself.
     */
    protected function requestPasswordReset(string $email): Response
    {
        $response = $this->postJson('/api/auth/forgot-password', ['email' => $email]);

        /** @var \Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $handler = self::getContainer()->get(\App\Security\Password\Message\PasswordResetRequestedHandler::class);

        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();

            if ($message instanceof \App\Security\Password\Message\PasswordResetRequested) {
                $handler($message);
            }
        }

        return $response;
    }

    /**
     * Requests a reset and returns the token from the emailed link.
     */
    protected function requestResetToken(string $email): string
    {
        $this->requestPasswordReset($email);

        $email = self::getMailerMessage();
        self::assertInstanceOf(Email::class, $email);

        if (1 !== preg_match('~token=([A-Za-z0-9]{40})\b~', (string) $email->getTextBody(), $matches)) {
            self::fail('No reset token found in the email.');
        }

        return $matches[1];
    }

    /**
     * Status code of an authenticated GET on a protected endpoint — tells whether an access token
     * is still accepted.
     */
    protected function statusWithAccessToken(string $accessToken): int
    {
        $this->client->request('GET', '/api/profile/trusted-devices', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$accessToken,
            'HTTP_ACCEPT' => 'application/json',
        ]);

        return $this->client->getResponse()->getStatusCode();
    }

    protected function refresh(): Response
    {
        $this->client->request('POST', '/api/auth/refresh', server: ['HTTP_X-Refresh-Request' => '1']);

        return $this->client->getResponse();
    }

    /**
     * Overrides the test client's cookie jar entry for the refresh token — used to replay an old
     * or forged value regardless of what the jar currently holds.
     */
    protected function setRefreshCookie(string $value): void
    {
        // Domain must match what the jar recorded from the real Set-Cookie response (the request
        // host, "localhost" for the test client) — otherwise this lands in a separate jar slot
        // instead of overwriting the real cookie, and the real one keeps winning.
        $this->client->getCookieJar()->set(new \Symfony\Component\BrowserKit\Cookie('refresh_token', $value, null, '/api/auth', 'localhost'));
    }

    /**
     * Step 1 only: posts credentials and returns the mfa_pending token from the response body.
     */
    protected function startLogin(string $email, string $password): string
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'email' => $email,
            'password' => $password,
        ], \JSON_THROW_ON_ERROR));

        $body = $this->decodeJson($this->client->getResponse());
        $pendingToken = $body['mfaPendingToken'] ?? null;

        self::assertIsString($pendingToken, 'Login step 1 did not return a pending MFA token — response was: '.json_encode($body));

        return $pendingToken;
    }

    /**
     * Reads the 6-digit code out of the most recently sent mail of the last request (the 2FA code email is sent
     * synchronously, so it's already in the logger by the time this is called).
     */
    protected function extractMfaCode(): string
    {
        $messages = self::getMailerMessages();
        $message = end($messages);
        self::assertNotFalse($message, 'No email was sent.');

        self::assertInstanceOf(Email::class, $message);
        $body = $message->getTextBody();
        self::assertIsString($body);

        if (1 !== preg_match('/\b(\d{6})\b/', $body, $matches)) {
            self::fail('No 6-digit code found in the email body.');
        }

        return $matches[1];
    }

    /**
     * Full nominal login: password + the code from the email it triggers. Returns the refresh
     * token cookie value.
     */
    protected function loginAndGetRefreshCookie(string $email, string $password, bool $trustDevice = false): string
    {
        $pendingToken = $this->startLogin($email, $password);
        $code = $this->extractMfaCode();

        $this->client->request('POST', '/api/auth/login/mfa/verify', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([
            'pendingToken' => $pendingToken,
            'code' => $code,
            'trustDevice' => $trustDevice,
        ], \JSON_THROW_ON_ERROR));

        return $this->extractCookieValue($this->client->getResponse(), 'refresh_token');
    }

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $server
     */
    protected function postJson(string $uri, array $data, array $server = []): Response
    {
        $this->client->request('POST', $uri, server: ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'] + $server, content: json_encode($data, \JSON_THROW_ON_ERROR));

        return $this->client->getResponse();
    }

    protected function submitMfaCode(string $pendingToken, string $code, bool $trustDevice = false): Response
    {
        return $this->postJson('/api/auth/login/mfa/verify', [
            'pendingToken' => $pendingToken,
            'code' => $code,
            'trustDevice' => $trustDevice,
        ]);
    }

    /**
     * Full nominal login (password + emailed code). Returns the access token.
     */
    protected function loginAndGetAccessToken(string $email, string $password, bool $trustDevice = false): string
    {
        $pendingToken = $this->startLogin($email, $password);
        $body = $this->decodeJson($this->submitMfaCode($pendingToken, $this->extractMfaCode(), $trustDevice));
        self::assertIsString($body['accessToken'] ?? null);

        return $body['accessToken'];
    }

    /**
     * A 6-digit code guaranteed to differ from $code.
     */
    protected function wrongCodeFor(string $code): string
    {
        return '000000' === $code ? '111111' : '000000';
    }

    /**
     * Runs a DQL UPDATE against every row of an entity — used to move timestamps into the past
     * (expiry, resend interval) without sleeping. Safe because DAMA rolls back each test.
     *
     * @param class-string $entityClass
     */
    protected function setOnAllRows(string $entityClass, string $field, mixed $value): void
    {
        $this->entityManager
            ->createQuery(sprintf('UPDATE %s e SET e.%s = :value', $entityClass, $field))
            ->setParameter('value', $value)
            ->execute();
        $this->entityManager->clear();
    }
}
