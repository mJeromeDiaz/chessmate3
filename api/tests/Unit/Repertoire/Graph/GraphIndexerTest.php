<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Graph;

use App\Repertoire\Graph\Graph;
use App\Repertoire\Graph\GraphIndexer;
use App\Repertoire\Graph\GraphMove;
use App\Repertoire\Graph\Index;
use PHPUnit\Framework\TestCase;

final class GraphIndexerTest extends TestCase
{
    public function testTheOpenBookRepertoireHasFiveSegments(): void
    {
        $graph = GraphFactory::fromPgn((string) file_get_contents(__DIR__.'/../../../Fixtures/Chess/openbook-white.pgn'))->graph();
        $index = (new GraphIndexer())->index($graph);

        self::assertSame([
            'trunk: d4 d5 Nc3 Nf6 Bf4 (3 user moves)',
            'Nc6 e3 Bf5 f3 e6 g4 Bg6 h4 (4 user moves)',
            'h6 Bd3 (1 user moves)',
            'h5 g5 Nd7 Bd3 Bxd3 Qxd3 Bb4 Ne2 Qe7 g6 (5 user moves)',
            'e6 e3 c5 Nb5 Qa5+ b4 Qxb4+ c3 Qa5 Bc7 b6 Nd6+ Bxd6 Bxd6 (7 user moves)',
        ], self::segments($graph, $index));
        self::assertPartition($graph, $index);
    }

    public function testNoTrunkWhenTheInitialPositionIsABranchingPoint(): void
    {
        $graph = (new GraphFactory('black'))->line('d4 d5 c4 e6')->line('c4 e5 Nc3 Nf6')->graph();

        self::assertSame(['d4 d5 c4 e6 (2 user moves)', 'c4 e5 Nc3 Nf6 (2 user moves)'], self::segments($graph, (new GraphIndexer())->index($graph)));
    }

    public function testASegmentWithoutAnyUserMove(): void
    {
        $graph = (new GraphFactory())->line('e4 e5 Nf3')->line('e4 c5')->graph();

        self::assertSame(['trunk: e4 (1 user moves)', 'e5 Nf3 (1 user moves)', 'c5 (0 user moves)'], self::segments($graph, (new GraphIndexer())->index($graph)));
    }

    public function testATranspositionEndsItsSegmentAndTheRestFollowsTheCanonicalPath(): void
    {
        // Against 1.d4 as Black: 2.c4 e6 3.Nf3 and 2.Nf3 e6 3.c4 reach the same position.
        $factory = (new GraphFactory('black'))
            ->line('d4 Nf6 c4 e6 Nf3 d5 Bg5 Be7')
            ->line('d4 Nf6 Nf3 e6 c4');
        $graph = $factory->graph();
        $index = (new GraphIndexer())->index($graph);

        self::assertSame([
            'trunk: d4 Nf6 (1 user moves)',
            'c4 e6 Nf3 d5 Bg5 Be7 (3 user moves)',
            'Nf3 e6 c4 (1 user moves)',
        ], self::segments($graph, $index));
        $transposition = $factory->moveId('d4 Nf6 Nf3 e6', 'c4');
        self::assertFalse($index->canonical[$transposition]);
        self::assertTrue($index->canonical[$factory->moveId('d4 Nf6 c4 e6', 'Nf3')]);
        self::assertSame(5, $index->depth[$graph->move($transposition)->to ?? '']);
        // The reply after the transposed position is tested once, in the canonical segment.
        self::assertSame($factory->moveId('d4 Nf6', 'c4'), $index->segmentOf[$factory->moveId('d4 Nf6 c4 e6 Nf3', 'd5')]);
        self::assertPartition($graph, $index);
    }

    public function testTheCurrentCanonicalMoveIsKeptWhileValid(): void
    {
        $factory = (new GraphFactory('black'))
            ->line('d4 Nf6 c4 e6 Nf3 d5')
            ->line('d4 Nf6 Nf3 e6 c4');
        $graph = $factory->graph();
        $first = $factory->moveId('d4 Nf6 c4 e6', 'Nf3');
        $second = $factory->moveId('d4 Nf6 Nf3 e6', 'c4');
        // Stored canonical flag on the newer move (e.g. the older one was deleted then restored).
        self::move($graph, $first)->canonical = false;
        self::move($graph, $second)->canonical = true;

        $index = (new GraphIndexer())->index($graph);

        self::assertTrue($index->canonical[$second]);
        self::assertFalse($index->canonical[$first]);
        self::assertSame($factory->moveId('d4 Nf6', 'Nf3'), $index->segmentOf[$factory->moveId('d4 Nf6 c4 e6 Nf3', 'd5')]);
    }

    private static function move(Graph $graph, string $id): GraphMove
    {
        return $graph->move($id) ?? throw new \LogicException($id);
    }

    /**
     * Each segment as "first moves... (n user moves)", the trunk first, then in creation order.
     *
     * @return list<string>
     */
    private static function segments(Graph $graph, Index $index): array
    {
        $moves = [];
        foreach ($index->segmentOf as $moveId => $key) {
            if (null !== $key) {
                $moves[$key][] = $graph->move($moveId)?->san;
            }
        }
        uksort($moves, static fn (string $a, string $b): int => [Index::TRUNK !== $a, $a] <=> [Index::TRUNK !== $b, $b]);
        $out = [];
        foreach ($moves as $key => $sans) {
            self::assertSame(\count($sans), $index->segments[$key]['moveCount']);
            $out[] = (Index::TRUNK === $key ? 'trunk: ' : '').implode(' ', $sans).sprintf(' (%d user moves)', $index->segments[$key]['userMoveCount']);
        }

        return $out;
    }

    /**
     * Every move the root reaches is in exactly one segment (every move is tested).
     */
    private static function assertPartition(Graph $graph, Index $index): void
    {
        foreach ($graph->moves() as $move) {
            $expected = isset($index->tested[$move->from]);
            self::assertSame($expected, null !== $index->segmentOf[$move->id], $move->san);
        }
    }
}
