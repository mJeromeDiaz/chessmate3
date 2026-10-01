<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

/**
 * A repertoire's graph in memory, ids as RFC 4122 strings: what {@see GraphIndexer} works on
 * (loaded by {@see GraphLoader}; a repertoire has at most a few thousand positions). Move ids are
 * UUID v7: their string order is their creation order.
 */
final class Graph
{
    /** @var array<string, GraphPosition> */
    private array $positions = [];
    /** @var array<string, GraphMove> */
    private array $moves = [];
    /** @var array<string, list<string>>|null position id => move ids, display order */
    private ?array $outgoing = null;
    /** @var array<string, list<string>>|null position id => move ids, creation order */
    private ?array $incoming = null;

    /**
     * @param string $userTurn 'w' or 'b': the side the repertoire is played with
     */
    public function __construct(
        public readonly string $rootId,
        public readonly string $userTurn,
    ) {
    }

    public function addPosition(GraphPosition $position): void
    {
        $this->positions[$position->id] = $position;
        $this->outgoing = $this->incoming = null;
    }

    public function addMove(GraphMove $move): void
    {
        $this->moves[$move->id] = $move;
        $this->outgoing = $this->incoming = null;
    }

    public function removeMove(string $id): void
    {
        unset($this->moves[$id]);
        $this->outgoing = $this->incoming = null;
    }

    public function removePosition(string $id): void
    {
        unset($this->positions[$id]);
        $this->outgoing = $this->incoming = null;
    }

    /**
     * @return array<string, GraphPosition>
     */
    public function positions(): array
    {
        return $this->positions;
    }

    /**
     * @return array<string, GraphMove>
     */
    public function moves(): array
    {
        return $this->moves;
    }

    public function position(string $id): ?GraphPosition
    {
        return $this->positions[$id] ?? null;
    }

    public function move(string $id): ?GraphMove
    {
        return $this->moves[$id] ?? null;
    }

    public function isUserTurn(string $positionId): bool
    {
        return ($this->positions[$positionId] ?? null)?->turn === $this->userTurn;
    }

    /**
     * Moves from a position, in display order (sort order, then creation).
     *
     * @return list<GraphMove>
     */
    public function outgoing(string $positionId): array
    {
        $this->index();

        return array_map(fn (string $id): GraphMove => $this->moves[$id], $this->outgoing[$positionId] ?? []);
    }

    /**
     * Moves into a position, in creation order.
     *
     * @return list<GraphMove>
     */
    public function incoming(string $positionId): array
    {
        $this->index();

        return array_map(fn (string $id): GraphMove => $this->moves[$id], $this->incoming[$positionId] ?? []);
    }

    /**
     * Positions reachable from the root.
     *
     * @return array<string, true>
     */
    public function reachable(): array
    {
        $seen = [$this->rootId => true];
        $queue = [$this->rootId];
        for ($i = 0; $i < \count($queue); ++$i) {
            $id = $queue[$i];
            foreach ($this->outgoing($id) as $move) {
                if (!isset($seen[$move->to])) {
                    $seen[$move->to] = true;
                    $queue[] = $move->to;
                }
            }
        }

        return $seen;
    }

    /**
     * What removing these moves cuts off: the positions the root no longer reaches, and the moves
     * removed or leaving them. Each lost position (and its moves) is given to the first removed
     * move it hangs from. The graph is left without them.
     *
     * @param list<string> $moveIds
     *
     * @return array<string, array{positions: list<string>, moves: list<string>}> removed move id => what goes with it
     */
    public function cut(array $moveIds): array
    {
        $removed = [];
        foreach ($moveIds as $id) {
            $removed[$id] = $this->move($id) ?? throw new \LogicException('No move '.$id);
        }
        $before = $this->moves;
        foreach ($moveIds as $id) {
            $this->removeMove($id);
        }
        $reachable = $this->reachable();
        $lost = [];
        foreach (array_keys($this->positions) as $id) {
            if (!isset($reachable[(string) $id])) {
                $lost[(string) $id] = true;
            }
        }

        /** @var array<string, list<GraphMove>> $leaving */
        $leaving = [];
        foreach ($before as $move) {
            $leaving[$move->from][] = $move;
        }
        $cut = [];
        $owner = [];
        foreach ($removed as $id => $move) {
            $cut[$id] = ['positions' => [], 'moves' => [$id]];
            $stack = isset($lost[$move->to]) && !isset($owner[$move->to]) ? [$move->to] : [];
            if ([] !== $stack) {
                $owner[$move->to] = $id;
            }
            while ([] !== $stack) {
                $position = array_pop($stack);
                $cut[$id]['positions'][] = $position;
                foreach ($leaving[$position] ?? [] as $candidate) {
                    if (isset($lost[$candidate->to]) && !isset($owner[$candidate->to])) {
                        $owner[$candidate->to] = $id;
                        $stack[] = $candidate->to;
                    }
                }
            }
        }
        foreach ($before as $move) {
            if (isset($owner[$move->from]) && !isset($removed[$move->id])) {
                $cut[$owner[$move->from]]['moves'][] = $move->id;
            }
        }
        foreach ($cut as $parts) {
            foreach ($parts['moves'] as $id) {
                if (null !== $this->move($id)) {
                    $this->removeMove($id);
                }
            }
            foreach ($parts['positions'] as $id) {
                $this->removePosition($id);
            }
        }

        return $cut;
    }

    /**
     * SAN moves of the canonical path from the root to a position.
     *
     * @return list<string>
     */
    public function canonicalPath(string $positionId): array
    {
        return array_map(static fn (GraphMove $move): string => $move->san, $this->canonicalMoves($positionId));
    }

    /**
     * Moves of the canonical path from the root to a position.
     *
     * @return list<GraphMove>
     */
    public function canonicalMoves(string $positionId): array
    {
        $path = [];
        $seen = [];
        while ($positionId !== $this->rootId && !isset($seen[$positionId])) {
            $seen[$positionId] = true;
            $in = $this->canonicalIncoming($positionId) ?? $this->incoming($positionId)[0] ?? null;
            if (null === $in) {
                break;
            }
            array_unshift($path, $in);
            $positionId = $in->from;
        }

        return $path;
    }

    /**
     * The canonical move into a position (none for the root, or before indexing).
     */
    public function canonicalIncoming(string $positionId): ?GraphMove
    {
        foreach ($this->incoming($positionId) as $move) {
            if ($move->canonical) {
                return $move;
            }
        }

        return null;
    }

    /**
     * Whether $to can be reached from $from (a move $from→... →$to exists).
     */
    public function leadsTo(string $from, string $to): bool
    {
        $seen = [$from => true];
        $stack = [$from];
        while ([] !== $stack) {
            $id = array_pop($stack);
            if ($id === $to) {
                return true;
            }
            foreach ($this->outgoing($id) as $move) {
                if (!isset($seen[$move->to])) {
                    $seen[$move->to] = true;
                    $stack[] = $move->to;
                }
            }
        }

        return false;
    }

    private function index(): void
    {
        if (null !== $this->outgoing && null !== $this->incoming) {
            return;
        }
        $moves = $this->moves;
        uasort($moves, static fn (GraphMove $a, GraphMove $b): int => [$a->sortOrder, $a->id] <=> [$b->sortOrder, $b->id]);
        $outgoing = [];
        foreach ($moves as $move) {
            $outgoing[$move->from][] = $move->id;
        }
        $ids = array_map('strval', array_keys($this->moves));
        sort($ids);
        $incoming = [];
        foreach ($ids as $id) {
            $incoming[$this->moves[$id]->to][] = $id;
        }
        $this->outgoing = $outgoing;
        $this->incoming = $incoming;
    }
}
