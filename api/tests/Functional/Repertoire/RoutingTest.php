<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\RouterInterface;

/**
 * `/repertoires/{id}` must never capture a sibling route (explorer, imports, stats... share the prefix).
 */
final class RoutingTest extends RepertoireWebTestCase
{
    private const UUID = '0192f6a0-1111-7000-8000-000000000000';
    private const MOVE = '0192f6a0-2222-7000-8000-000000000000';

    /**
     * @return iterable<array{string, string, string}>
     */
    public static function routes(): iterable
    {
        yield ['GET', '/api/repertoires', '_api_/repertoires_get_collection'];
        yield ['POST', '/api/repertoires', '_api_/repertoires_post'];
        yield ['GET', '/api/repertoires/'.self::UUID, '_api_/repertoires/{id}_get'];
        yield ['DELETE', '/api/repertoires/'.self::UUID, '_api_/repertoires/{id}_delete'];
        yield ['POST', '/api/repertoires/'.self::UUID.'/rename', 'repertoire_rename'];
        yield ['GET', '/api/repertoires/'.self::UUID.'/graph', '_api_/repertoires/{id}/graph_get'];
        yield ['POST', '/api/repertoires/'.self::UUID.'/moves', 'repertoire_move_add'];
        yield ['POST', '/api/repertoires/'.self::UUID.'/moves/'.self::MOVE.'/replace', 'repertoire_move_replace'];
        yield ['POST', '/api/repertoires/'.self::UUID.'/moves/'.self::MOVE.'/promote', 'repertoire_move_promote'];
        yield ['POST', '/api/repertoires/'.self::UUID.'/moves/'.self::MOVE.'/annotation', 'repertoire_move_annotate'];
        yield ['POST', '/api/repertoires/'.self::UUID.'/moves/'.self::MOVE.'/delete', 'repertoire_move_delete'];
        yield ['POST', '/api/repertoires/'.self::UUID.'/undo', 'repertoire_undo'];
        yield ['GET', '/api/repertoires/'.self::UUID.'/export', 'repertoire_export'];
        yield ['GET', '/api/repertoires/'.self::UUID.'/trash', 'repertoire_trash_list'];
        yield ['GET', '/api/repertoires/'.self::UUID.'/trash/'.self::MOVE, 'repertoire_trash_preview'];
        yield ['DELETE', '/api/repertoires/'.self::UUID.'/trash/'.self::MOVE, 'repertoire_trash_discard'];
        yield ['POST', '/api/repertoires/'.self::UUID.'/trash/'.self::MOVE.'/restore', 'repertoire_trash_restore'];
        yield ['POST', '/api/repertoires/imports', '_api_/repertoires/imports_post'];
        yield ['GET', '/api/repertoires/imports/'.self::UUID, '_api_/repertoires/imports/{id}_get'];
        yield ['POST', '/api/repertoires/imports/'.self::UUID.'/apply', 'repertoire_import_apply'];
        yield ['GET', '/api/repertoires/explorer/masters', '_api_/repertoires/explorer/{source}_get'];
        yield ['GET', '/api/repertoires/explorer/lichess', '_api_/repertoires/explorer/{source}_get'];
        yield ['GET', '/api/repertoires/cloud-eval', '_api_/repertoires/cloud-eval_get'];
        yield ['GET', '/api/repertoires/stats', 'repertoire_overview'];
        yield ['GET', '/api/repertoires/'.self::UUID.'/stats', '_api_/repertoires/{id}/stats_get'];
        yield ['GET', '/api/repertoires/'.self::UUID.'/segments/'.self::MOVE, '_api_/repertoires/{repertoireId}/segments/{id}_get'];
        yield ['GET', '/api/repertoires/runs/'.self::UUID, '_api_/repertoires/runs/{id}_get'];
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

        self::assertSame(404, $this->api('GET', '/api/repertoires/not-a-uuid', $user)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/repertoires/not-a-uuid/graph', $user)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/repertoires/not-a-uuid/moves', $user, [])->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/repertoires/'.self::UUID.'/moves/nope/delete', $user, [])->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/repertoires/'.self::UUID.'/change', $user)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/repertoires/'.self::UUID.'/trash/nope', $user)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/repertoires/'.self::UUID.'/trash/nope/restore', $user, [])->getStatusCode());
    }
}
