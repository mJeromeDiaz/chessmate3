<?php

declare(strict_types=1);

namespace App\Tests\Functional\PuzzleStreak;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The puzzle streak's routes (docs/PUZZLE_STREAK.md): the overview and the openings, siblings.
 */
final class RoutingTest extends WebTestCase
{
    /**
     * @return iterable<array{string, string}>
     */
    public static function routes(): iterable
    {
        yield ['/api/puzzle-streak', '_api_/puzzle-streak_get'];
        yield ['/api/puzzle-streak/openings', '_api_/puzzle-streak/openings_get'];
    }

    #[DataProvider('routes')]
    public function testEachPathMatchesItsOwnRoute(string $path, string $route): void
    {
        self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);
        $router->getContext()->setMethod('GET');

        self::assertSame($route, $router->match($path)['_route']);
    }

    public function testSignedInOnly(): void
    {
        $client = self::createClient();
        foreach (['/api/puzzle-streak', '/api/puzzle-streak/openings'] as $path) {
            $client->request('GET', $path);
            self::assertSame(401, $client->getResponse()->getStatusCode(), $path);
        }
    }
}
