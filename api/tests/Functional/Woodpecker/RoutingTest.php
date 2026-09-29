<?php

declare(strict_types=1);

namespace App\Tests\Functional\Woodpecker;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouterInterface;

/**
 * `/woodpecker/sets/{id}` and `/woodpecker/attempts/{id}` must never capture a sibling route.
 */
final class RoutingTest extends WoodpeckerWebTestCase
{
    private const UUID = '0192f6a0-1111-7000-8000-000000000000';

    /**
     * @return iterable<array{string, string, string}>
     */
    public static function routes(): iterable
    {
        yield ['GET', '/api/woodpecker/sets', '_api_/woodpecker/sets_get_collection'];
        yield ['POST', '/api/woodpecker/sets', '_api_/woodpecker/sets_post'];
        yield ['GET', '/api/woodpecker/sets/'.self::UUID, '_api_/woodpecker/sets/{id}_get'];
        yield ['POST', '/api/woodpecker/sets/'.self::UUID.'/pause', 'woodpecker_set_pause'];
        yield ['POST', '/api/woodpecker/sets/'.self::UUID.'/resume', 'woodpecker_set_resume'];
        yield ['POST', '/api/woodpecker/sets/'.self::UUID.'/abandon', 'woodpecker_set_abandon'];
        yield ['POST', '/api/woodpecker/sets/'.self::UUID.'/archive', 'woodpecker_set_archive'];
        yield ['GET', '/api/woodpecker/sets/'.self::UUID.'/stubborn', '_api_/woodpecker/sets/{setId}/stubborn_get_collection'];
        yield ['POST', '/api/woodpecker/sets/'.self::UUID.'/attempts', '_api_/woodpecker/sets/{setId}/attempts_post'];
        yield ['POST', '/api/woodpecker/attempts/'.self::UUID.'/submission', '_api_/woodpecker/attempts/{id}/submission_post'];
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
        $user = $this->createUserIn('alice@example.com');

        self::assertSame(404, $this->api('GET', '/api/woodpecker/sets/not-a-uuid', $user)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/woodpecker/sets/not-a-uuid/pause', $user)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/woodpecker/sets/not-a-uuid/stubborn', $user)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/woodpecker/attempts/not-a-uuid/submission', $user, ['moves' => []])->getStatusCode());
    }
}
