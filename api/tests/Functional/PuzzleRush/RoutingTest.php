<?php

declare(strict_types=1);

namespace App\Tests\Functional\PuzzleRush;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * The puzzle rush's route (docs/PUZZLE_RUSH.md): the overview, next to the puzzle streak's.
 */
final class RoutingTest extends WebTestCase
{
    public function testThePathMatchesItsOwnRoute(): void
    {
        self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);
        $router->getContext()->setMethod('GET');

        self::assertSame('_api_/puzzle-rush_get', $router->match('/api/puzzle-rush')['_route']);
        self::assertSame('_api_/puzzle-streak_get', $router->match('/api/puzzle-streak')['_route']);
    }

    public function testSignedInOnly(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/puzzle-rush');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
