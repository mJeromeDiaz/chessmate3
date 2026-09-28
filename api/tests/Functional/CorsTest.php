<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Credentialed CORS must only ever answer the SPA's exact origin (CORS_ALLOW_ORIGINS).
 */
final class CorsTest extends WebTestCase
{
    public function testTheSpaOriginMayCallTheRefreshEndpointWithCredentials(): void
    {
        $response = $this->preflight('http://localhost:9000');

        self::assertSame('http://localhost:9000', $response->headers->get('Access-Control-Allow-Origin'));
        self::assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        self::assertStringContainsStringIgnoringCase('x-refresh-request', (string) $response->headers->get('Access-Control-Allow-Headers'));
    }

    #[DataProvider('foreignOrigins')]
    public function testAnyOtherOriginIsRefused(string $origin): void
    {
        self::assertNull($this->preflight($origin)->headers->get('Access-Control-Allow-Origin'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function foreignOrigins(): iterable
    {
        yield 'another local port' => ['http://localhost:3000'];
        yield 'lookalike host' => ['http://localhost:9000.evil.example'];
        yield 'https variant' => ['https://localhost:9000'];
        yield 'null origin' => ['null'];
        yield 'third party' => ['https://evil.example'];
    }

    private function preflight(string $origin): \Symfony\Component\HttpFoundation\Response
    {
        $client = static::createClient();
        $client->request('OPTIONS', '/api/auth/refresh', server: [
            'HTTP_ORIGIN' => $origin,
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'x-refresh-request',
        ]);

        return $client->getResponse();
    }
}
