<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SecurityHeadersTest extends WebTestCase
{
    public function testBaselineSecurityHeadersArePresentOnEveryResponse(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/does-not-exist');

        $response = $client->getResponse();

        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertSame('DENY', $response->headers->get('X-Frame-Options'));
        self::assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
        self::assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        self::assertStringContainsString('max-age=', (string) $response->headers->get('Strict-Transport-Security'));
    }
}
