<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

/**
 * POST /repertoires/{id}/lines: a line drafted in the editor saved in one change
 * (docs/REPERTOIRE.md, "Brouillon").
 *
 * @phpstan-import-type GraphJson from RepertoireWebTestCase
 */
final class LineApiTest extends RepertoireWebTestCase
{
    public function testALineIsSavedInOneChangeAndUndoneAtOnce(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $base = '/api/repertoires/'.$repertoire['id'];
        $root = $this->graph($alice, $repertoire['id'])['rootPositionId'];

        $saved = $this->change($alice, $base.'/lines', ['fromPositionId' => $root, 'ucis' => ['e2e4', 'e7e5', 'g1f3', 'b8c6'], 'baseVersion' => 0]);
        self::assertSame(['import', 1], [$saved['operation'], $saved['version']]);
        self::assertSame(['Nc6 reply', 'Nf3 reference', 'e4 reference', 'e5 reply'], self::moves($saved['moves']));
        $graph = $this->graph($alice, $repertoire['id']);
        self::assertCount(5, $graph['positions']);
        self::assertTrue(array_reduce($graph['moves'], static fn (bool $all, array $move): bool => $all && $move['canonical'], true));

        $undone = $this->change($alice, $base.'/undo');
        self::assertSame('undo', $undone['operation']);
        self::assertCount(4, $undone['deletedMoveIds'], 'the whole line at once');
        self::assertCount(1, $this->graph($alice, $repertoire['id'])['positions']);
    }

    public function testMovesAlreadyThereAreJoined(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $base = '/api/repertoires/'.$repertoire['id'];
        $this->playLine($alice, $repertoire['id'], ['e2e4', 'e7e5']);
        $graph = $this->graph($alice, $repertoire['id']);

        $fromRoot = $this->change($alice, $base.'/lines', ['fromPositionId' => $graph['rootPositionId'], 'ucis' => ['e2e4', 'e7e5', 'g1f3', 'b8c6']]);
        self::assertSame('import', $fromRoot['operation']);
        self::assertCount(4, $this->graph($alice, $repertoire['id'])['moves'], 'e4 and e5 joined, Nf3 and Nc6 added');

        // From a position along the way, with a new reply: 2...d6 3.d4.
        $afterNf3 = self::positionAfter($this->graph($alice, $repertoire['id']), ['e2e4', 'e7e5', 'g1f3']);
        $this->change($alice, $base.'/lines', ['fromPositionId' => $afterNf3, 'ucis' => ['d7d6', 'd2d4']]);
        $graph = $this->graph($alice, $repertoire['id']);
        self::assertCount(6, $graph['moves']);
        self::positionAfter($graph, ['e2e4', 'e7e5', 'g1f3', 'd7d6', 'd2d4']);

        $known = $this->change($alice, $base.'/lines', ['fromPositionId' => $graph['rootPositionId'], 'ucis' => ['e2e4', 'e7e5']]);
        self::assertSame(['none', $graph['version']], [$known['operation'], $known['version']], 'nothing new: no change');
    }

    public function testAnotherPreparedMoveOnTheWayIsReplacedOnlyWhenAsked(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $base = '/api/repertoires/'.$repertoire['id'];
        $this->playLine($alice, $repertoire['id'], ['e2e4', 'e7e5', 'g1f3', 'b8c6']);
        $graph = $this->graph($alice, $repertoire['id']);
        $line = ['fromPositionId' => $graph['rootPositionId'], 'ucis' => ['e2e4', 'e7e5', 'f1c4', 'g8f6']];

        $refused = $this->api('POST', $base.'/lines', $alice, $line);
        self::assertSame(409, $refused->getStatusCode(), '2.Nf3 is prepared there');
        self::assertSame('position_occupied', $this->json($refused)['detail'] ?? null);
        self::assertSame($graph['version'], $this->graph($alice, $repertoire['id'])['version'], 'nothing written');

        $replaced = $this->change($alice, $base.'/lines', [...$line, 'replace' => true]);
        self::assertSame('import', $replaced['operation']);
        self::assertCount(2, $replaced['deletedMoveIds'], '2.Nf3 Nc6');
        $graph = $this->graph($alice, $repertoire['id']);
        self::assertSame(['Bc4 reference', 'Nf6 reply', 'e4 reference', 'e5 reply'], self::moves($graph['moves']));
        $trash = $this->trash($alice, $repertoire['id']);
        self::assertSame([['replaced', 'Nf3', 2]], array_map(static fn (array $suite): array => [$suite['reason'], $suite['san'], $suite['moveCount']], $trash));

        $this->change($alice, $base.'/undo');
        self::assertSame(['Nc6 reply', 'Nf3 reference', 'e4 reference', 'e5 reply'], self::moves($this->graph($alice, $repertoire['id'])['moves']), 'undo brings 2.Nf3 back');
        self::assertSame([], $this->trash($alice, $repertoire['id']));
    }

    public function testRefusedLines(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->createRepertoireVia($alice);
        $base = '/api/repertoires/'.$repertoire['id'];
        $root = $this->graph($alice, $repertoire['id'])['rootPositionId'];

        self::assertSame(422, $this->api('POST', $base.'/lines', $alice, ['fromPositionId' => $root, 'ucis' => ['e2e4', 'e2e4']])->getStatusCode(), 'illegal');
        self::assertSame(422, $this->api('POST', $base.'/lines', $alice, ['fromPositionId' => $root, 'ucis' => ['castle']])->getStatusCode(), 'not UCI');
        self::assertSame(422, $this->api('POST', $base.'/lines', $alice, ['fromPositionId' => $root, 'ucis' => []])->getStatusCode(), 'empty');
        self::assertSame(422, $this->api('POST', $base.'/lines', $alice, ['fromPositionId' => 'x', 'ucis' => ['e2e4']])->getStatusCode());
        self::assertSame(422, $this->api('POST', $base.'/lines', $alice, ['fromPositionId' => $root, 'ucis' => ['g1f3', 'g8f6', 'f3g1', 'f6g8']])->getStatusCode(), 'back to the initial position');
        self::assertSame(409, $this->api('POST', $base.'/lines', $alice, ['fromPositionId' => $root, 'ucis' => ['e2e4'], 'baseVersion' => 3])->getStatusCode(), 'stale version');
        self::assertSame(404, $this->api('POST', $base.'/lines', $alice, ['fromPositionId' => '0192f6a0-1111-7000-8000-000000000000', 'ucis' => ['e2e4']])->getStatusCode(), 'unknown position');
        self::assertCount(1, $this->graph($alice, $repertoire['id'])['positions'], 'nothing written');

        // 1.Nf3 Nf6 2.Ng1 in the repertoire: 2...Ng8 from there goes back to the start.
        $this->playLine($alice, $repertoire['id'], ['g1f3', 'g8f6', 'f3g1']);
        $afterNg1 = self::positionAfter($this->graph($alice, $repertoire['id']), ['g1f3', 'g8f6', 'f3g1']);
        self::assertSame(422, $this->api('POST', $base.'/lines', $alice, ['fromPositionId' => $afterNg1, 'ucis' => ['f6g8']])->getStatusCode(), 'repeated position through the graph');

        $other = $this->createRepertoireVia($alice, 'Autre');
        self::assertSame(404, $this->api('POST', '/api/repertoires/'.$other['id'].'/lines', $alice, ['fromPositionId' => $root, 'ucis' => ['e2e4']])->getStatusCode(), "another repertoire's position");
        $bob = $this->createUser('bob@example.com');
        self::assertSame(404, $this->api('POST', $base.'/lines', $bob, ['fromPositionId' => $root, 'ucis' => ['e2e4']])->getStatusCode(), "another user's repertoire");
    }

    /**
     * "SAN role" of each move, sorted (the API gives no order).
     *
     * @param list<array{san: string, role: string}> $moves
     *
     * @return list<string>
     */
    private static function moves(array $moves): array
    {
        $labels = array_map(static fn (array $move): string => $move['san'].' '.$move['role'], $moves);
        sort($labels);

        return $labels;
    }

    /**
     * The position reached by these moves from the initial one.
     *
     * @param GraphJson    $graph
     * @param list<string> $ucis
     */
    private static function positionAfter(array $graph, array $ucis): string
    {
        $position = $graph['rootPositionId'];
        foreach ($ucis as $uci) {
            $next = array_values(array_filter($graph['moves'], static fn (array $move): bool => $move['from'] === $position && $move['uci'] === $uci))[0] ?? null;
            self::assertNotNull($next, 'No move '.$uci);
            $position = $next['to'];
        }

        return $position;
    }
}
