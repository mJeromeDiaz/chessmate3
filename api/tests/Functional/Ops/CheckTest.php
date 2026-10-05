<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ops;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The deployment checks (docs/DEPLOY_OVH.md): `GET /api/ops/check` behind the operations secret,
 * and `app:deploy:check`. No outgoing connection here (the network check is opt-in).
 */
final class CheckTest extends WebTestCase
{
    private const TOKEN = 'test-tick-token-0123456789abcdef-0123';

    public function testTheWebCheckNeedsTheSecretAndShowsTheClientIp(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/ops/check');
        self::assertSame(404, $client->getResponse()->getStatusCode());

        $client->request('GET', '/api/ops/check', server: ['HTTP_X_TICK_TOKEN' => self::TOKEN, 'REMOTE_ADDR' => '203.0.113.7']);
        $response = $client->getResponse();

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{ok: bool, checks: list<array{name: string, level: string, detail: string}>, clientIp: string|null, remoteAddr: string|null} $body */
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('203.0.113.7', $body['clientIp']);
        self::assertSame('203.0.113.7', $body['remoteAddr']);
        $checks = array_column($body['checks'], null, 'name');
        self::assertSame('ok', $checks['MySQL']['level'] ?? null);
        self::assertSame('ok', $checks['ext-sodium']['level'] ?? null);
        self::assertSame('ok', $checks['Messenger table']['level'] ?? null);
        // Not production: reported, not fatal.
        self::assertSame('warning', $checks['APP_ENV']['level'] ?? null);
        self::assertArrayNotHasKey('Outgoing HTTPS', $checks);
    }

    public function testTheCommandPrintsTheChecks(): void
    {
        self::bootKernel();
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException('No kernel.')))->find('app:deploy:check'));

        $tester->execute([]);

        $display = $tester->getDisplay();
        self::assertStringContainsString('MySQL', $display);
        self::assertStringContainsString('OPS_TICK_TOKEN', $display);
        self::assertStringContainsString('WARNING', $display);
    }
}
