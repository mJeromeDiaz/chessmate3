<?php

declare(strict_types=1);

namespace App\Repertoire\Training;

use App\Enum\Repertoire\MoveRole;
use App\Enum\Repertoire\TestUnit;
use App\Repertoire\Graph\Graph;
use App\Repertoire\Graph\GraphMove;

/**
 * The units a repertoire test presents (docs/REPERTOIRE.md), from an indexed graph (stored
 * canonical moves and segments). Pure.
 *
 * - Segment: its moves in order; its context is the canonical path to its start. A segment
 *   without any user move is not presented.
 * - Line: a path of the canonical tree from the initial position to a leaf, as the segments it
 *   crosses (those with user moves); identified by its last segment. A line ends where its last
 *   segment ends: a leaf, or a transposition (the rest belongs to the canonical path).
 */
final class UnitBuilder
{
    /** @var array<string, list<GraphMove>> segment id => its moves, in order */
    private array $chains = [];
    /** @var array<string, string|null> segment id => the segment it continues */
    private array $parents = [];

    /**
     * @param array<string, array{eco: string, name: string}> $openings position id => its opening name (named positions only)
     */
    public function __construct(
        private readonly Graph $graph,
        private readonly array $openings = [],
    ) {
        foreach ($graph->moves() as $move) {
            if (null !== $move->segmentId) {
                $this->chains[$move->segmentId][] = $move;
            }
        }
        foreach ($this->chains as $id => $moves) {
            // A segment is a path of the canonical tree (a transposition, if any, last): its moves
            // come in the order of the depth they start from.
            usort($moves, fn (GraphMove $a, GraphMove $b): int => [$this->depth($a->from), $a->id] <=> [$this->depth($b->from), $b->id]);
            $this->chains[$id] = $moves;
            $this->parents[$id] = $graph->canonicalIncoming($moves[0]->from)?->segmentId;
        }
    }

    /**
     * @param array<string, true>|null $only segment ids (null: all)
     *
     * @return list<UnitPlan> the presentable units, in the graph's display order
     */
    public function units(TestUnit $unit, ?array $only = null): array
    {
        $presentable = array_filter($this->chains, fn (array $moves): bool => $this->userMoves($moves) > 0);
        $plans = [];
        if (TestUnit::Segment === $unit) {
            foreach ($presentable as $id => $moves) {
                $id = (string) $id;
                if (null === $only || isset($only[$id])) {
                    $plans[] = $this->segment($id);
                }
            }
        } else {
            $parents = [];
            foreach (array_keys($presentable) as $id) {
                if (null !== ($this->parents[(string) $id] ?? null)) {
                    $parents[(string) $this->parents[(string) $id]] = true;
                }
            }
            foreach (array_keys($presentable) as $id) {
                $id = (string) $id;
                if (isset($parents[$id])) {
                    continue;
                }
                $line = $this->line($id);
                if (null === $only || [] !== array_intersect_key(array_flip($line->segmentIds()), $only)) {
                    $plans[] = $line;
                }
            }
        }
        usort($plans, fn (UnitPlan $a, UnitPlan $b): int => $this->order($a) <=> $this->order($b));

        return $plans;
    }

    /**
     * The unit keyed $key, if still presentable.
     */
    public function unit(TestUnit $unit, string $key): ?UnitPlan
    {
        if (!isset($this->chains[$key]) || 0 === $this->userMoves($this->chains[$key])) {
            return null;
        }

        return TestUnit::Segment === $unit ? $this->segment($key) : $this->line($key);
    }

    /**
     * The label of a segment: a deviation is the deepest opening named on its canonical path up to
     * it, and the move numbered ("3…c5"); the trunk, the deepest opening named along it.
     *
     * @return array{opening: array{eco: string, name: string}|null, move: string|null}
     */
    public function label(string $segmentId): array
    {
        $first = ($this->chains[$segmentId] ?? [])[0] ?? null;
        if (null === $first) {
            return ['opening' => null, 'move' => null];
        }
        $deviation = $this->isDeviation($first);
        $opening = null;
        foreach ([...$this->graph->canonicalMoves($first->from), ...($deviation ? [$first] : $this->chains[$segmentId])] as $move) {
            $opening = $this->openings[$move->to] ?? $opening;
        }

        return ['opening' => $opening, 'move' => $deviation ? self::numbered($this->depth($first->from), $first->san) : null];
    }

    /**
     * Active segment ids with at least one user move, in display order.
     *
     * @return list<string>
     */
    public function presentable(): array
    {
        return array_map(static fn (UnitPlan $plan): string => $plan->key, $this->units(TestUnit::Segment));
    }

    /**
     * SAN moves from the initial position to the segment's first move, included.
     *
     * @return list<string>
     */
    public function path(string $segmentId): array
    {
        $first = ($this->chains[$segmentId] ?? [])[0] ?? null;

        return null === $first ? [] : [...$this->graph->canonicalPath($first->from), $first->san];
    }

    /**
     * "3.Bf4" or "3…c5".
     */
    public static function numbered(int $ply, string $san): string
    {
        return sprintf('%d%s%s', intdiv($ply, 2) + 1, 0 === $ply % 2 ? '.' : '…', $san);
    }

    /**
     * The segments with a move leaving one of these positions or a position they lead to (the
     * sub-tree of the editor's "Test this line"; a segment it starts in the middle of is in).
     *
     * @return array<string, true>
     */
    public function segmentsUnder(string $positionId): array
    {
        $seen = [$positionId => true];
        $stack = [$positionId];
        $segments = [];
        while ([] !== $stack) {
            $id = array_pop($stack);
            foreach ($this->graph->outgoing($id) as $move) {
                if (null !== $move->segmentId) {
                    $segments[$move->segmentId] = true;
                }
                if (!isset($seen[$move->to])) {
                    $seen[$move->to] = true;
                    $stack[] = $move->to;
                }
            }
        }

        return $segments;
    }

    private function segment(string $id): UnitPlan
    {
        $moves = $this->chains[$id];

        return new UnitPlan(
            TestUnit::Segment,
            $id,
            array_map(static fn (GraphMove $move): string => $move->id, $this->graph->canonicalMoves($moves[0]->from)),
            [['segmentId' => $id, 'moveIds' => self::ids($moves)]],
            $this->isDeviation($moves[0]),
            [$id => $this->label($id)],
        );
    }

    private function line(string $leaf): UnitPlan
    {
        $segments = [];
        $seen = [];
        for ($id = $leaf; null !== $id && !isset($seen[$id]); $id = $this->parents[$id] ?? null) {
            $seen[$id] = true;
            if (isset($this->chains[$id]) && $this->userMoves($this->chains[$id]) > 0) {
                array_unshift($segments, ['segmentId' => $id, 'moveIds' => self::ids($this->chains[$id])]);
            }
        }
        // Anything above the first segment kept is played without asking (normally nothing: the
        // first one starts at the initial position).
        $first = $this->chains[$segments[0]['segmentId']][0];

        return new UnitPlan(
            TestUnit::Line,
            $leaf,
            array_map(static fn (GraphMove $move): string => $move->id, $this->graph->canonicalMoves($first->from)),
            $segments,
            false,
            array_combine(array_column($segments, 'segmentId'), array_map($this->label(...), array_column($segments, 'segmentId'))),
        );
    }

    /** An opponent's move leaving a branching point (the initial position included). */
    private function isDeviation(GraphMove $move): bool
    {
        return MoveRole::Reply === $move->role && \count($this->graph->outgoing($move->from)) >= 2;
    }

    /**
     * @param list<GraphMove> $moves
     */
    private function userMoves(array $moves): int
    {
        return \count(array_filter($moves, static fn (GraphMove $move): bool => MoveRole::Reference === $move->role));
    }

    /**
     * @param list<GraphMove> $moves
     *
     * @return list<string>
     */
    private static function ids(array $moves): array
    {
        return array_map(static fn (GraphMove $move): string => $move->id, $moves);
    }

    private function depth(string $positionId): int
    {
        return $this->graph->position($positionId)->depth ?? 0;
    }

    /**
     * Display order: the depth where its key segment starts, then the main line first.
     *
     * @return list<int|string>
     */
    private function order(UnitPlan $plan): array
    {
        $first = $this->chains[$plan->key][0];

        return [$this->depth($first->from), $first->sortOrder, $first->id];
    }
}
