<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use App\Enum\Repertoire\MoveRole;

/**
 * Derives from a repertoire graph (docs/REPERTOIRE.md):
 *
 * - the canonical tree: one canonical move into each position. The current one is kept while it
 *   is still valid (stability: segments and statistics follow it), that is, leaves a position
 *   the root reaches; otherwise the oldest valid move into the position is chosen. The other moves
 *   into a position are transpositions;
 * - depths: the ply of each position on its canonical path;
 * - segments: a branching point is a position where the opponent is to move and which has at least
 *   two replies. A segment starts at the initial position (the trunk, unless the initial position
 *   is itself a branching point) or with a reply leaving a branching point, and every other move
 *   belongs to the segment of the canonical move into its starting position. A transposition
 *   belongs to the segment it ends. Every move is tested: a user's position holds one move only.
 *
 * Pure: the caller writes the differences back ({@see IndexWriter}).
 */
final class GraphIndexer
{
    public function index(Graph $graph): Index
    {
        // Every move is tested since a user's position holds one move only: the tested part of
        // the graph is what the root reaches.
        $reachable = $graph->reachable();
        $tested = $reachable;

        // Canonical moves.
        $canonical = [];
        foreach ($graph->positions() as $position) {
            if ($position->id === $graph->rootId) {
                continue;
            }
            $incoming = array_values(array_filter($graph->incoming($position->id), static fn (GraphMove $move): bool => isset($reachable[$move->from])));
            $valid = $incoming;
            $current = array_values(array_filter($valid, static fn (GraphMove $move): bool => $move->canonical));
            $chosen = $current[0] ?? $valid[0] ?? null;
            foreach ($graph->incoming($position->id) as $move) {
                $canonical[$move->id] = $move === $chosen;
            }
        }

        // Depths and segments, walking the canonical tree from the root.
        $depth = [$graph->rootId => 0];
        $segmentOf = [];
        $segments = [];
        /** @var array<string, string|null> $segmentAt position id => segment its outgoing moves continue */
        $segmentAt = [$graph->rootId => $this->isBranching($graph, $graph->rootId) ? null : Index::TRUNK];
        $queue = [$graph->rootId];
        for ($i = 0; $i < \count($queue); ++$i) {
            $id = $queue[$i];
            $branching = $this->isBranching($graph, $id);
            foreach ($graph->outgoing($id) as $move) {
                $key = null;
                if (isset($tested[$id])) {
                    $key = $branching ? $move->id : $segmentAt[$id];
                }
                $segmentOf[$move->id] = $key;
                if (null !== $key) {
                    $segments[$key] ??= ['moveCount' => 0, 'userMoveCount' => 0];
                    ++$segments[$key]['moveCount'];
                    if (MoveRole::Reference === $move->role) {
                        ++$segments[$key]['userMoveCount'];
                    }
                }
                if ($canonical[$move->id] ?? false) {
                    $depth[$move->to] = $depth[$id] + 1;
                    $segmentAt[$move->to] = $key;
                    $queue[] = $move->to;
                }
            }
        }

        return new Index($canonical, $depth, $segmentOf, $segments, $tested);
    }

    /**
     * A position where the opponent is to move and which has at least two (tested) replies.
     */
    private function isBranching(Graph $graph, string $positionId): bool
    {
        if ($graph->isUserTurn($positionId)) {
            return false;
        }
        $replies = array_filter($graph->outgoing($positionId), static fn (GraphMove $move): bool => MoveRole::Reply === $move->role);

        return \count($replies) >= 2;
    }
}
