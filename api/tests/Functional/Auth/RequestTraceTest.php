<?php

declare(strict_types=1);

namespace App\Tests\Functional\Auth;

use App\Security\Trace\Redactor;
use App\Security\Trace\RequestTraceListener;
use Monolog\Handler\TestHandler;

/**
 * The request trace log (docs/SECURITY.md, § 2.12): which requests are traced, and that no secret
 * ever reaches it.
 */
final class RequestTraceTest extends AuthWebTestCase
{
    private const PASSWORD = 'CorrectHorseBatteryStaple9!';

    public function testLoginIsTracedWithoutThePassword(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);

        $response = $this->postJson('/api/auth/login', ['email' => 'alice@example.com', 'password' => self::PASSWORD]);

        $trace = $this->singleTrace();
        self::assertSame('POST', $trace['method']);
        self::assertSame('/api/auth/login', $trace['path']);
        self::assertSame(202, $response->getStatusCode()); // step 1: the emailed code is pending
        self::assertSame(202, $trace['status']);
        self::assertSame(['email' => 'alice@example.com', 'password' => Redactor::MASK], $trace['body']);
        self::assertNull($trace['userId']);
        self::assertIsInt($trace['durationMs']);
        self::assertSame('127.0.0.1', $trace['ip']);
        $this->assertNotInTraces(self::PASSWORD);
    }

    public function testMfaCodeAndPendingTokenAreMasked(): void
    {
        $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $pendingToken = $this->startLogin('alice@example.com', self::PASSWORD);
        $code = $this->extractMfaCode();

        $this->submitMfaCode($pendingToken, $code);

        $trace = $this->singleTrace();
        self::assertSame(['pendingToken' => Redactor::MASK, 'code' => Redactor::MASK, 'trustDevice' => false], $trace['body']);
        $this->assertNotInTraces($pendingToken);
    }

    public function testAuthenticatedRequestIsTracedWithTheUserAndATokenFingerprint(): void
    {
        $user = $this->createVerifiedUser('alice@example.com', self::PASSWORD);
        $accessToken = $this->loginAndGetAccessToken('alice@example.com', self::PASSWORD);

        $this->client->request('GET', '/api/profile', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$accessToken, 'HTTP_ACCEPT' => 'application/json']);

        $trace = $this->singleTrace();
        self::assertSame('GET', $trace['method']);
        self::assertSame(200, $trace['status']);
        self::assertSame($user->getId()->toRfc4122(), $trace['userId']);
        self::assertArrayNotHasKey('body', $trace);

        $token = $trace['token'];
        self::assertIsArray($token);
        self::assertSame(substr(hash('sha256', $accessToken), 0, 16), $token['fingerprint']);
        self::assertIsInt($token['iat']);
        self::assertIsInt($token['exp']);
        self::assertSame(900, $token['exp'] - $token['iat']);
        $this->assertNotInTraces($accessToken);
    }

    public function testRefusedRequestToAProtectedRouteIsTraced(): void
    {
        $this->client->request('GET', '/api/profile', server: ['HTTP_AUTHORIZATION' => 'Bearer not.a.jwt', 'HTTP_ACCEPT' => 'application/json']);

        $trace = $this->singleTrace();
        self::assertSame(401, $trace['status']);
        self::assertNull($trace['userId']);
        // An unverified token only gets its fingerprint: its claims could be forged.
        self::assertSame(['fingerprint' => substr(hash('sha256', 'not.a.jwt'), 0, 16), 'iat' => null, 'exp' => null], $trace['token']);
    }

    public function testQueryStringSecretsAreMasked(): void
    {
        $this->client->request('GET', '/api/auth/oauth/google/callback?code=the-oauth-code&state=the-state&error=x');

        $trace = $this->singleTrace();
        self::assertSame(['code' => Redactor::MASK, 'state' => Redactor::MASK, 'error' => 'x'], $trace['query']);
        $this->assertNotInTraces('the-oauth-code');
    }

    public function testLongBodyIsCutAfterRedaction(): void
    {
        $this->postJson('/api/auth/login', ['password' => self::PASSWORD, 'email' => str_repeat('a', 10_000).'@example.com']);

        $trace = $this->singleTrace();
        self::assertIsString($trace['body']);
        self::assertSame(RequestTraceListener::MAX_BODY_BYTES, \strlen($trace['body']));
        self::assertStringStartsWith('{"password":"[redacted]","email":"aaa', $trace['body']);
        self::assertTrue($trace['bodyTruncated']);
        self::assertGreaterThan(10_000, $trace['bodyBytes']);
        $this->assertNotInTraces(self::PASSWORD);
    }

    public function testNonJsonBodyIsOnlyMeasured(): void
    {
        $this->client->request('POST', '/api/auth/login', server: ['CONTENT_TYPE' => 'application/json'], content: 'password=hunter2');

        self::assertSame('[16 bytes, not JSON]', $this->singleTrace()['body']);
        $this->assertNotInTraces('hunter2');
    }

    public function testFormBodyIsRedacted(): void
    {
        $this->client->request('POST', '/api/auth/oauth/google/redirect', ['invitationKey' => 'the-invitation-key']);

        self::assertSame(['invitationKey' => Redactor::MASK], $this->singleTrace()['body']);
        $this->assertNotInTraces('the-invitation-key');
    }

    public function testPublicRoutesAreNotTraced(): void
    {
        $this->client->request('GET', '/api/ops/check');
        self::assertCount(0, $this->traces());

        $this->client->request('GET', '/api/calendar/unknown-token.ics');
        self::assertCount(0, $this->traces());
    }

    /**
     * @return list<array<string, mixed>> the traces of the last request
     */
    private function traces(): array
    {
        // Test-only service (config/services.yaml, when@test): PHPStan reads the dev container.
        // @phpstan-ignore symfonyContainer.serviceNotFound
        $handler = self::getContainer()->get('app.trace.test_handler');
        self::assertInstanceOf(TestHandler::class, $handler);

        $traces = [];
        foreach ($handler->getRecords() as $record) {
            /** @var array<string, mixed> $context */
            $context = $record->context;
            $traces[] = $context;
        }

        return $traces;
    }

    /**
     * @return array<string, mixed>
     */
    private function singleTrace(): array
    {
        $traces = $this->traces();
        self::assertCount(1, $traces);

        return $traces[0];
    }

    private function assertNotInTraces(string $secret): void
    {
        self::assertStringNotContainsString($secret, json_encode($this->traces(), \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
    }
}
