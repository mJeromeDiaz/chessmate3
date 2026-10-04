<?php

declare(strict_types=1);

namespace App\Tests\Functional\Training;

use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouterInterface;

/**
 * `/training/runs/{id}` and `/training/sessions/{id}` must never capture a sibling route (`current`
 * in particular).
 */
final class RoutingTest extends WoodpeckerWebTestCase
{
    private const UUID = '0192f6a0-1111-7000-8000-000000000000';

    /**
     * @return iterable<array{string, string, string}>
     */
    public static function routes(): iterable
    {
        yield ['POST', '/api/training/runs', '_api_/training/runs_post'];
        yield ['GET', '/api/training/runs/current', 'training_run_current'];
        yield ['GET', '/api/training/runs/'.self::UUID, '_api_/training/runs/{id}_get'];
        yield ['POST', '/api/training/runs/'.self::UUID.'/next', 'training_run_next'];
        yield ['POST', '/api/training/runs/'.self::UUID.'/submission', 'training_run_submission'];
        yield ['POST', '/api/training/runs/'.self::UUID.'/stop', 'training_run_stop'];
        yield ['POST', '/api/training/sessions', '_api_/training/sessions_post'];
        yield ['GET', '/api/training/sessions', '_api_/training/sessions_get_collection'];
        yield ['GET', '/api/training/sessions/current', 'training_session_current'];
        yield ['GET', '/api/training/sessions/'.self::UUID, '_api_/training/sessions/{id}_get'];
        yield ['POST', '/api/training/sessions/'.self::UUID.'/next', 'training_session_next'];
        yield ['POST', '/api/training/sessions/'.self::UUID.'/skip', 'training_session_skip'];
        yield ['POST', '/api/training/sessions/'.self::UUID.'/abandon', 'training_session_abandon'];
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

        self::assertSame(404, $this->api('GET', '/api/training/runs/not-a-uuid', $user)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/training/runs/not-a-uuid/next', $user)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/training/runs/not-a-uuid/stop', $user)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/training/runs/not-a-uuid/submission', $user, ['itemId' => 'x'])->getStatusCode());
        foreach (['GET /api/training/sessions/not-a-uuid', 'POST /api/training/sessions/not-a-uuid/next', 'POST /api/training/sessions/not-a-uuid/skip', 'POST /api/training/sessions/not-a-uuid/abandon', 'GET /api/training/sessions/'.self::UUID] as $request) {
            [$method, $path] = explode(' ', $request);
            self::assertSame(404, $this->api($method, $path, $user)->getStatusCode(), $request);
        }
    }
}
