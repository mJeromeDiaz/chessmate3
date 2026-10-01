<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Training;

use App\Enum\Repertoire\TestUnit;
use App\Repertoire\Graph\Graph;
use App\Repertoire\Graph\GraphIndexer;
use App\Repertoire\Training\UnitBuilder;
use App\Repertoire\Training\UnitPlan;
use App\Tests\Unit\Repertoire\Graph\GraphFactory;
use PHPUnit\Framework\TestCase;

final class UnitBuilderTest extends TestCase
{
    public function testOpenBookSegmentsCarryTheirContextAndDeviation(): void
    {
        $graph = self::indexed(GraphFactory::fromPgn(self::openBook())->graph());

        self::assertSame([
            '[] d4 d5 Nc3 Nf6 Bf4',
            '[d4 d5 Nc3 Nf6 Bf4] deviation: Nc6 e3 Bf5 f3 e6 g4 Bg6 h4',
            '[d4 d5 Nc3 Nf6 Bf4] deviation: e6 e3 c5 Nb5 Qa5+ b4 Qxb4+ c3 Qa5 Bc7 b6 Nd6+ Bxd6 Bxd6',
            '[d4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4] deviation: h6 Bd3',
            '[d4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4] deviation: h5 g5 Nd7 Bd3 Bxd3 Qxd3 Bb4 Ne2 Qe7 g6',
        ], self::describe($graph, (new UnitBuilder($graph))->units(TestUnit::Segment)));
    }

    public function testOpenBookHasThreeLinesMadeOfTheirSegments(): void
    {
        $graph = self::indexed(GraphFactory::fromPgn(self::openBook())->graph());

        self::assertSame([
            '[] d4 d5 Nc3 Nf6 Bf4 | e6 e3 c5 Nb5 Qa5+ b4 Qxb4+ c3 Qa5 Bc7 b6 Nd6+ Bxd6 Bxd6',
            '[] d4 d5 Nc3 Nf6 Bf4 | Nc6 e3 Bf5 f3 e6 g4 Bg6 h4 | h6 Bd3',
            '[] d4 d5 Nc3 Nf6 Bf4 | Nc6 e3 Bf5 f3 e6 g4 Bg6 h4 | h5 g5 Nd7 Bd3 Bxd3 Qxd3 Bb4 Ne2 Qe7 g6',
        ], self::describe($graph, (new UnitBuilder($graph))->units(TestUnit::Line)));
    }

    public function testABlackTrunkStartsWithTheOpponentsMoveWithoutDeviation(): void
    {
        $graph = self::indexed((new GraphFactory('black'))->line('d4 d5 c4 e6')->line('d4 d5 Nf3 Nf6')->graph());

        self::assertSame([
            '[] d4 d5',
            '[d4 d5] deviation: c4 e6',
            '[d4 d5] deviation: Nf3 Nf6',
        ], self::describe($graph, (new UnitBuilder($graph))->units(TestUnit::Segment)));
    }

    public function testAnInitialBranchingPointMakesDeviationsAndNoTrunk(): void
    {
        $graph = self::indexed((new GraphFactory('black'))->line('d4 d5 c4 e6')->line('c4 e5 Nc3 Nf6')->graph());

        self::assertSame([
            '[] deviation: d4 d5 c4 e6',
            '[] deviation: c4 e5 Nc3 Nf6',
        ], self::describe($graph, (new UnitBuilder($graph))->units(TestUnit::Segment)));
    }

    public function testASegmentWithoutUserMoveIsNeitherASegmentNorALine(): void
    {
        $factory = (new GraphFactory())->line('e4 e5 Nf3')->line('e4 c5');
        $graph = self::indexed($factory->graph());
        $builder = new UnitBuilder($graph);

        self::assertSame(['[] e4', '[e4] deviation: e5 Nf3'], self::describe($graph, $builder->units(TestUnit::Segment)));
        self::assertSame(['[] e4 | e5 Nf3'], self::describe($graph, $builder->units(TestUnit::Line)));
        self::assertNull($builder->unit(TestUnit::Segment, (string) $graph->move($factory->moveId('e4', 'c5'))?->segmentId));
    }

    public function testATranspositionEndsItsSegmentAndItsLine(): void
    {
        $factory = (new GraphFactory('black'))
            ->line('d4 Nf6 c4 e6 Nf3 d5 Bg5 Be7')
            ->line('d4 Nf6 Nf3 e6 c4');
        $graph = self::indexed($factory->graph());
        $builder = new UnitBuilder($graph);

        self::assertSame([
            '[] d4 Nf6',
            '[d4 Nf6] deviation: c4 e6 Nf3 d5 Bg5 Be7',
            '[d4 Nf6] deviation: Nf3 e6 c4',
        ], self::describe($graph, $builder->units(TestUnit::Segment)));
        self::assertSame([
            '[] d4 Nf6 | c4 e6 Nf3 d5 Bg5 Be7',
            '[] d4 Nf6 | Nf3 e6 c4',
        ], self::describe($graph, $builder->units(TestUnit::Line)));
    }

    public function testTheSubTreeOfAPositionSelectsTheSegmentsItTouches(): void
    {
        $factory = GraphFactory::fromPgn(self::openBook());
        $graph = self::indexed($factory->graph());
        $builder = new UnitBuilder($graph);
        // In the middle of the 3...Nc6 segment, after 5.f3.
        $position = (string) $graph->move($factory->moveId('d4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5', 'f3'))?->to;

        self::assertSame([
            '[d4 d5 Nc3 Nf6 Bf4] deviation: Nc6 e3 Bf5 f3 e6 g4 Bg6 h4',
            '[d4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4] deviation: h6 Bd3',
            '[d4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4] deviation: h5 g5 Nd7 Bd3 Bxd3 Qxd3 Bb4 Ne2 Qe7 g6',
        ], self::describe($graph, $builder->units(TestUnit::Segment, $builder->segmentsUnder($position))));
        self::assertCount(2, $builder->units(TestUnit::Line, $builder->segmentsUnder($position)));
    }

    public function testLabelsNameTheDeepestOpeningAndTheDeviation(): void
    {
        $factory = GraphFactory::fromPgn(self::openBook());
        $graph = self::indexed($factory->graph());
        $after = static fn (string $path, string $san): string => (string) $graph->move($factory->moveId($path, $san))?->to;
        $builder = new UnitBuilder($graph, [
            $after('d4', 'd5') => ['eco' => 'D00', 'name' => "Queen's Pawn Game"],
            $after('d4 d5 Nc3 Nf6', 'Bf4') => ['eco' => 'D00', 'name' => 'Jobava London System'],
            $after('d4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4', 'h5') => ['eco' => 'D00', 'name' => 'Deep name'],
        ]);
        $segment = static fn (string $path, string $san): string => (string) $graph->move($factory->moveId($path, $san))?->segmentId;

        self::assertSame(['opening' => ['eco' => 'D00', 'name' => 'Jobava London System'], 'move' => null], $builder->label($segment('', 'd4')), 'the trunk');
        self::assertSame(['opening' => ['eco' => 'D00', 'name' => 'Jobava London System'], 'move' => '3…Nc6'], $builder->label($segment('d4 d5 Nc3 Nf6 Bf4', 'Nc6')));
        self::assertSame(['opening' => ['eco' => 'D00', 'name' => 'Deep name'], 'move' => '7…h5'], $builder->label($segment('d4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4', 'h5')));
        self::assertSame(['d4', 'd5', 'Nc3', 'Nf6', 'Bf4', 'Nc6'], $builder->path($segment('d4 d5 Nc3 Nf6 Bf4', 'Nc6')));

        $line = $builder->unit(TestUnit::Line, $segment('d4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4', 'h6'));
        self::assertSame('7…h6', $line?->label()['move'], 'a line is labelled by its last segment');
        self::assertCount(3, $line->labels);
    }

    /** Stores the derived data as the editor does: canonical moves, depths, segments (keyed by the index's keys). */
    private static function indexed(Graph $graph): Graph
    {
        $index = (new GraphIndexer())->index($graph);
        foreach ($graph->moves() as $move) {
            $move->canonical = $index->canonical[$move->id] ?? false;
            $move->segmentId = $index->segmentOf[$move->id] ?? null;
        }
        foreach ($graph->positions() as $position) {
            $position->depth = $index->depth[$position->id] ?? 0;
        }

        return $graph;
    }

    /**
     * "[context] deviation: segment | segment".
     *
     * @param list<UnitPlan> $plans
     *
     * @return list<string>
     */
    private static function describe(Graph $graph, array $plans): array
    {
        $san = static fn (string $id): string => $graph->move($id)->san ?? '?';

        return array_map(static fn (UnitPlan $plan): string => sprintf(
            '[%s] %s%s',
            implode(' ', array_map($san, $plan->contextMoveIds)),
            $plan->deviation ? 'deviation: ' : '',
            implode(' | ', array_map(static fn (array $segment): string => implode(' ', array_map($san, $segment['moveIds'])), $plan->segments)),
        ), $plans);
    }

    private static function openBook(): string
    {
        return (string) file_get_contents(__DIR__.'/../../../Fixtures/Chess/openbook-white.pgn');
    }
}
