<?php

declare(strict_types=1);

namespace App\Repertoire\Training;

use App\Enum\Repertoire\TestUnit;

/**
 * A unit of a repertoire test as moves of the graph ({@see UnitBuilder}): a segment, or a whole
 * line (its segments, top down). Ids as RFC 4122 strings.
 */
final readonly class UnitPlan
{
    /**
     * @param string                                               $key            the segment, or the last segment of the line
     * @param list<string>                                         $contextMoveIds the canonical path to the start, played without asking
     * @param list<array{segmentId: string, moveIds: list<string>}> $segments       with at least one user move each
     * @param bool                                                 $deviation      the first move is the opponent's leaving a branching point
     * @param array<string, array{opening: array{eco: string, name: string}|null, move: string|null}> $labels segment id => its label
     */
    public function __construct(
        public TestUnit $unit,
        public string $key,
        public array $contextMoveIds,
        public array $segments,
        public bool $deviation,
        public array $labels = [],
    ) {
    }

    /**
     * The unit's label: its key segment's.
     *
     * @return array{opening: array{eco: string, name: string}|null, move: string|null}
     */
    public function label(): array
    {
        return $this->labels[$this->key] ?? ['opening' => null, 'move' => null];
    }

    /**
     * @return list<string>
     */
    public function segmentIds(): array
    {
        return array_map(static fn (array $segment): string => $segment['segmentId'], $this->segments);
    }

    /**
     * @return list<string>
     */
    public function moveIds(): array
    {
        return array_merge(...array_map(static fn (array $segment): array => $segment['moveIds'], $this->segments));
    }
}
