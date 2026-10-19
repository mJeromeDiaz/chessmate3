<?php

declare(strict_types=1);

namespace App\Tests\Functional\Blindfold;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Routing\RouterInterface;

/**
 * GET /blindfold/recall next to GET /blindfold/puzzles (docs/BLINDFOLD_RECALL.md).
 */
final class RecallRoutingTest extends WebTestCase
{
    public function testThePathMatchesItsOwnRoute(): void
    {
        self::createClient();
        $router = self::getContainer()->get(RouterInterface::class);
        $router->getContext()->setMethod('GET');

        self::assertSame('_api_/blindfold/recall_get', $router->match('/api/blindfold/recall')['_route']);
        self::assertSame('_api_/blindfold/puzzles_get', $router->match('/api/blindfold/puzzles')['_route']);
    }

    public function testSignedInOnly(): void
    {
        $client = self::createClient();
        $client->request('GET', '/api/blindfold/recall');
        self::assertSame(401, $client->getResponse()->getStatusCode());
    }
}
