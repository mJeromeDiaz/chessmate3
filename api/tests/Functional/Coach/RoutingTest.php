<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coach;

use App\Tests\Functional\Repertoire\RepertoireWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouterInterface;

/**
 * The tailored session's routes (docs/COACH.md), and malformed build ids.
 */
final class RoutingTest extends RepertoireWebTestCase
{
    private const UUID = '0192f6a0-1111-7000-8000-000000000000';

    /**
     * @return iterable<array{string, string, string}>
     */
    public static function routes(): iterable
    {
        yield ['GET', '/api/coach/builds/'.self::UUID.'/diagnosis', '_api_/coach/builds/{id}/diagnosis_get'];
        yield ['POST', '/api/coach/builds/'.self::UUID.'/session', '_api_/coach/builds/{id}/session_post'];
    }

    #[DataProvider('routes')]
    public function testEachPathMatchesItsOwnRoute(string $method, string $path, string $route): void
    {
        $router = self::getContainer()->get(RouterInterface::class);
        $router->getContext()->setMethod($method);

        self::assertSame($route, $router->match($path)['_route']);
    }

    public function testMalformedIdsAnswer404(): void
    {
        $user = $this->createUser('alice@example.com');

        self::assertSame(404, $this->api('GET', '/api/coach/builds/not-a-uuid/diagnosis', $user)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/coach/builds/not-a-uuid/session', $user, [])->getStatusCode());
    }
}
