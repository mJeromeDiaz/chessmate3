<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * @phpstan-import-type MoveJson from RepertoireWebTestCase
 */
final class RepertoireApiTest extends RepertoireWebTestCase
{
    private const E4 = ['e2e4', 'e7e5', 'g1f3'];

    public function testCreateListRenameAndDelete(): void
    {
        $alice = $this->createUser('alice@example.com');
        $first = $this->createRepertoireVia($alice, 'Blancs : 1.e4');
        $second = $this->createRepertoireVia($alice, '  Noirs contre 1.d4 ', 'black');
        $this->createRepertoireVia($this->createUser('bob@example.com'), 'Bob');

        self::assertSame('Noirs contre 1.d4', $second['name']);
        self::assertSame(['black', 1, 0, 0], [$second['color'], $second['positionCount'], $second['segmentCount'], $second['version']]);
        $list = $this->json($this->api('GET', '/api/repertoires', $alice));
        self::assertSame([$second['id'], $first['id']], array_column(self::members($list), 'id'), 'mine only, newest first');

        $renamed = $this->json($this->api('POST', '/api/repertoires/'.$first['id'].'/rename', $alice, ['name' => 'Blancs : 1.e4 e5']));
        self::assertSame('Blancs : 1.e4 e5', $renamed['name']);

        self::assertSame(204, $this->api('DELETE', '/api/repertoires/'.$first['id'], $alice)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/repertoires/'.$first['id'], $alice)->getStatusCode());
    }

    public function testInvalidInputIsRejected(): void
    {
        $alice = $this->createUser('alice@example.com');

        self::assertSame(422, $this->api('POST', '/api/repertoires', $alice, ['name' => '   ', 'color' => 'white'])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/repertoires', $alice, ['name' => 'X', 'color' => 'green'])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/repertoires', $alice, ['name' => str_repeat('x', 81), 'color' => 'white'])->getStatusCode());
    }

    public function testAnotherUsersRepertoireAnswers404Everywhere(): void
    {
        $alice = $this->createUser('alice@example.com');
        $mallory = $this->createUser('mallory@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $changes = $this->playLine($alice, $repertoire['id'], self::E4);
        $root = $this->graph($alice, $repertoire['id'])['rootPositionId'];
        $moveId = (string) $changes[0]['moveId'];
        $base = '/api/repertoires/'.$repertoire['id'];

        self::assertSame(404, $this->api('GET', $base, $mallory)->getStatusCode());
        self::assertSame(404, $this->api('GET', $base.'/graph', $mallory)->getStatusCode());
        self::assertSame(404, $this->api('POST', $base.'/rename', $mallory, ['name' => 'Mine'])->getStatusCode());
        self::assertSame(404, $this->api('DELETE', $base, $mallory)->getStatusCode());
        self::assertSame(404, $this->api('POST', $base.'/moves', $mallory, ['fromPositionId' => $root, 'uci' => 'd2d4'])->getStatusCode());
        self::assertSame(404, $this->api('POST', $base.'/moves/'.$moveId.'/replace', $mallory, ['uci' => 'd2d4'])->getStatusCode());
        self::assertSame(404, $this->api('GET', $base.'/trash', $mallory)->getStatusCode());
        foreach (['promote', 'delete'] as $action) {
            self::assertSame(404, $this->api('POST', $base.'/moves/'.$moveId.'/'.$action, $mallory, [])->getStatusCode(), $action);
        }
        self::assertSame(404, $this->api('POST', $base.'/moves/'.$moveId.'/annotation', $mallory, ['comment' => 'x'])->getStatusCode());
        self::assertSame(404, $this->api('POST', $base.'/undo', $mallory, [])->getStatusCode());

        // Mallory's own repertoire, with Alice's position or move ids: not found either.
        $own = $this->createRepertoireVia($mallory);
        self::assertSame(404, $this->api('POST', '/api/repertoires/'.$own['id'].'/moves', $mallory, ['fromPositionId' => $root, 'uci' => 'd2d4'])->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/repertoires/'.$own['id'].'/moves/'.$moveId.'/delete', $mallory, [])->getStatusCode());
        self::assertCount(4, $this->graph($alice, $repertoire['id'])['positions'], 'nothing changed');
    }

    public function testTheGraphHoldsPositionsMovesSegmentsAndOpeningNames(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $this->playLine($alice, $repertoire['id'], self::E4);
        $this->playLine($alice, $repertoire['id'], ['e2e4', 'c7c5']);

        $graph = $this->graph($alice, $repertoire['id']);

        self::assertSame(4, $graph['version']);
        self::assertCount(5, $graph['positions']);
        self::assertCount(4, $graph['moves']);
        $byFen = array_column($graph['positions'], null, 'fen');
        self::assertNull($byFen['rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -']['opening']);
        self::assertSame(['eco' => 'B00', 'name' => "King's Pawn Game"], $byFen['rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq -']['opening']);
        self::assertSame('Sicilian Defense', $byFen['rnbqkbnr/pp1ppppp/8/2p5/4P3/8/PPPP1PPP/RNBQKBNR w KQkq -']['opening']['name'] ?? null);
        $moves = array_column($graph['moves'], null, 'san');
        self::assertSame(['reference', true, 0], [$moves['e4']['role'], $moves['e4']['canonical'], $moves['e4']['sortOrder']]);
        self::assertSame(['reply', 1], [$moves['c5']['role'], $moves['c5']['sortOrder']]);
        self::assertCount(3, $graph['segments'], 'trunk 1.e4, 1...e5 2.Nf3, 1...c5');
        $list = self::members($this->json($this->api('GET', '/api/repertoires', $alice)));
        self::assertSame(2, $list[0]['segmentCount'], 'the segment 1...c5 has no user move');
    }

    public function testAChangeReturnsWhatItTouched(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        [$e4] = $this->playLine($alice, $repertoire['id'], ['e2e4']);

        self::assertSame(['add', 1, false, null], [$e4['operation'], $e4['version'], $e4['transposition'], $e4['trashId']]);
        self::assertCount(1, $e4['positions']);
        self::assertSame('e4', $e4['moves'][0]['san'] ?? null);
        self::assertNotNull($e4['moves'][0]['segmentId'] ?? null);
        self::assertSame($e4['moves'][0]['segmentId'], $e4['segments'][0]['id'] ?? null);

        $root = $this->graph($alice, $repertoire['id'])['rootPositionId'];
        $refused = $this->api('POST', '/api/repertoires/'.$repertoire['id'].'/moves', $alice, ['fromPositionId' => $root, 'uci' => 'd2d4']);
        self::assertSame(409, $refused->getStatusCode(), 'one prepared move per position: replace it');
        self::assertSame('position_occupied', $this->json($refused)['detail'] ?? null);
    }

    public function testATranspositionIsReported(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice, 'Noirs', 'black');
        $this->playLine($alice, $repertoire['id'], ['d2d4', 'g8f6', 'c2c4', 'e7e6', 'g1f3', 'd7d5']);
        $changes = $this->playLine($alice, $repertoire['id'], ['d2d4', 'g8f6', 'g1f3', 'e7e6', 'c2c4']);

        $last = end($changes);
        self::assertNotFalse($last);
        self::assertTrue($last['transposition']);
        self::assertCount(9, $this->graph($alice, $repertoire['id'])['positions']);
    }

    public function testRefusedChanges(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $base = '/api/repertoires/'.$repertoire['id'];
        $root = $this->graph($alice, $repertoire['id'])['rootPositionId'];

        self::assertSame(422, $this->api('POST', $base.'/moves', $alice, ['fromPositionId' => $root, 'uci' => 'e2e5'])->getStatusCode(), 'illegal');
        self::assertSame(422, $this->api('POST', $base.'/moves', $alice, ['fromPositionId' => $root, 'uci' => 'castle'])->getStatusCode(), 'not UCI');
        self::assertSame(422, $this->api('POST', $base.'/moves', $alice, ['fromPositionId' => 'x', 'uci' => 'e2e4'])->getStatusCode());
        self::assertSame(409, $this->api('POST', $base.'/undo', $alice, [])->getStatusCode(), 'nothing to undo');

        [$e4] = $this->playLine($alice, $repertoire['id'], ['e2e4']);
        self::assertSame(409, $this->api('POST', $base.'/moves', $alice, ['fromPositionId' => $e4['moves'][0]['to'], 'uci' => 'e7e5', 'baseVersion' => 0])->getStatusCode(), 'stale version');
        self::assertSame(422, $this->api('POST', $base.'/moves/'.$e4['moveId'].'/replace', $alice, ['uci' => 'e2e5'])->getStatusCode(), 'illegal replacement');
        $e5 = $this->change($alice, $base.'/moves', ['fromPositionId' => $e4['moves'][0]['to'], 'uci' => 'e7e5']);
        self::assertSame(422, $this->api('POST', $base.'/moves/'.$e5['moveId'].'/replace', $alice, ['uci' => 'c7c5'])->getStatusCode(), 'a reply is not replaced');

        // 1.Nf3 Nf6 2.Ng1 Ng8 would lead back to the initial position.
        $other = $this->createRepertoireVia($alice, 'Autre');
        $base = '/api/repertoires/'.$other['id'];
        $this->playLine($alice, $other['id'], ['g1f3', 'g8f6', 'f3g1']);
        $graph = $this->graph($alice, $other['id']);
        $ng1 = array_values(array_filter($graph['moves'], static fn (array $move): bool => 'f3g1' === $move['uci']))[0];
        self::assertSame(422, $this->api('POST', $base.'/moves', $alice, ['fromPositionId' => $ng1['to'], 'uci' => 'f6g8'])->getStatusCode(), 'repeated position');
    }

    public function testAnnotatePromoteDeleteAndUndo(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $base = '/api/repertoires/'.$repertoire['id'];
        [$e4] = $this->playLine($alice, $repertoire['id'], self::E4);
        $this->playLine($alice, $repertoire['id'], ['e2e4', 'c7c5']);
        $graph = $this->graph($alice, $repertoire['id']);
        $c5 = self::bySan($graph['moves'], 'c5');

        $annotated = $this->change($alice, $base.'/moves/'.$e4['moveId'].'/annotation', ['comment' => '<b>Best</b> by test', 'nags' => [1]]);
        self::assertCount(1, $annotated['moves']);
        self::assertSame('<b>Best</b> by test', $annotated['moves'][0]['comment'], 'plain text, returned as is');
        self::assertSame([1], $annotated['moves'][0]['nags']);
        self::assertSame(422, $this->api('POST', $base.'/moves/'.$e4['moveId'].'/annotation', $alice, ['nags' => [1, 2]])->getStatusCode());
        self::assertSame(422, $this->api('POST', $base.'/moves/'.$e4['moveId'].'/annotation', $alice, ['comment' => str_repeat('a', 2001)])->getStatusCode());

        $promoted = $this->change($alice, $base.'/moves/'.$c5['id'].'/promote');
        self::assertSame(0, self::bySan($promoted['moves'], 'c5')['sortOrder']);

        $deleted = $this->change($alice, $base.'/moves/'.$e4['moveId'].'/delete');
        self::assertCount(4, $deleted['deletedMoveIds'], '1.e4 e5 2.Nf3, 1...c5');
        self::assertCount(4, $deleted['deletedPositionIds']);
        self::assertNotNull($deleted['trashId']);

        $undone = $this->change($alice, $base.'/undo');
        self::assertSame('undo', $undone['operation']);
        foreach ($deleted['deletedMoveIds'] as $id) {
            self::assertContains($id, array_column($undone['moves'], 'id'), 'restored with its id');
        }
        self::assertCount(\count($graph['positions']), $this->graph($alice, $repertoire['id'])['positions']);
        self::assertSame([], $this->trash($alice, $repertoire['id']), 'taken back from the trash');
    }

    public function testReplaceThenRestoreFromTheTrash(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $base = '/api/repertoires/'.$repertoire['id'];
        [$e4] = $this->playLine($alice, $repertoire['id'], self::E4);

        $replaced = $this->change($alice, $base.'/moves/'.$e4['moveId'].'/replace', ['uci' => 'd2d4']);
        self::assertSame('replace', $replaced['operation']);
        self::assertSame('reference', self::bySan($replaced['moves'], 'd4')['role']);
        self::assertContains($e4['moveId'], $replaced['deletedMoveIds']);
        self::assertCount(3, $replaced['deletedPositionIds']);

        $trash = $this->trash($alice, $repertoire['id']);
        self::assertCount(1, $trash);
        self::assertSame($replaced['trashId'], $trash[0]['id']);
        self::assertSame(['replaced', 'e4', 'e2e4', [], 3, 3], [$trash[0]['reason'], $trash[0]['san'], $trash[0]['uci'], $trash[0]['path'], $trash[0]['positionCount'], $trash[0]['moveCount']]);

        // The preview names the conflict: 1.e4 back means 1.d4 goes.
        $trashId = (string) $replaced['trashId'];
        $preview = $this->trashPreview($alice, $repertoire['id'], $trashId);
        self::assertTrue($preview['restorable']);
        self::assertNotNull($preview['preview']);
        $conflict = $preview['preview']['conflicts'][0];
        self::assertSame(['e4', 'd4', 'restored'], [$conflict['restored']['san'], $conflict['current']['san'], $conflict['choice']]);
        self::assertSame([3, 3, 1], [$preview['preview']['positions'], $preview['preview']['moves'], $preview['preview']['replaced']]);
        $fen = $conflict['fen'];
        $kept = $this->trashPreview($alice, $repertoire['id'], $trashId, [$fen => 'current'])['preview'];
        self::assertNotNull($kept);
        self::assertSame([0, 0, 1], [$kept['moves'], $kept['replaced'], $kept['leftOut']]);

        $restored = $this->change($alice, $base.'/trash/'.$trashId.'/restore', ['choices' => [$fen => 'restored']]);
        self::assertSame('restore', $restored['operation']);
        self::assertContains($e4['moveId'], array_column($restored['moves'], 'id'), 'back with its id');
        $trash = $this->trash($alice, $repertoire['id']);
        self::assertSame(['d4'], array_column($trash, 'san'), '1.d4 went to the trash in turn');

        self::assertSame(422, $this->api('POST', $base.'/trash/'.$trash[0]['id'].'/restore', $alice, ['choices' => [$fen => 'both']])->getStatusCode());
        self::assertSame(204, $this->api('DELETE', $base.'/trash/'.$trash[0]['id'], $alice)->getStatusCode());
        self::assertSame(404, $this->api('GET', $base.'/trash/'.$trash[0]['id'], $alice)->getStatusCode());
        self::assertSame(404, $this->api('POST', $base.'/trash/'.$trash[0]['id'].'/restore', $alice, [])->getStatusCode());
    }

    public function testASuiteWhoseStartIsGoneIsShownNotRestorable(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $base = '/api/repertoires/'.$repertoire['id'];
        $changes = $this->playLine($alice, $repertoire['id'], self::E4);
        $inner = $this->change($alice, $base.'/moves/'.$changes[2]['moveId'].'/delete');
        $this->change($alice, $base.'/moves/'.$changes[0]['moveId'].'/delete');

        $preview = $this->trashPreview($alice, $repertoire['id'], (string) $inner['trashId']);
        self::assertSame([false, null], [$preview['restorable'], $preview['preview']]);
        $refused = $this->api('POST', $base.'/trash/'.(string) $inner['trashId'].'/restore', $alice, []);
        self::assertSame(409, $refused->getStatusCode());
        self::assertSame('start_missing', $this->json($refused)['detail'] ?? null);
    }

    public function testEditingIsRateLimited(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $root = $this->graph($alice, $repertoire['id'])['rootPositionId'];
        /** @var RateLimiterFactory $limiter */
        $limiter = self::getContainer()->get('limiter.repertoire_edit');
        $limiter->create($alice->getId()->toRfc4122())->consume(600);

        self::assertSame(429, $this->api('POST', '/api/repertoires/'.$repertoire['id'].'/moves', $alice, ['fromPositionId' => $root, 'uci' => 'e2e4'])->getStatusCode());
    }

    public function testEndpointsRequireAuthentication(): void
    {
        $this->client->request('GET', '/api/repertoires');

        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /**
     * @param list<MoveJson> $moves
     *
     * @return MoveJson
     */
    private static function bySan(array $moves, string $san): array
    {
        foreach ($moves as $move) {
            if ($move['san'] === $san) {
                return $move;
            }
        }
        self::fail('No move '.$san);
    }

    /**
     * @param array<string, mixed> $collection
     *
     * @return list<array{id: string, segmentCount: int}>
     */
    private static function members(array $collection): array
    {
        /** @var list<array{id: string, segmentCount: int}> */
        return $collection['member'] ?? [];
    }
}
