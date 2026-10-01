<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

/**
 * What {@see GraphIndexer} derives from a graph: the canonical tree, depths and the segments.
 * A segment key is {@see self::TRUNK} or the id of the segment's first move.
 */
final readonly class Index
{
    public const TRUNK = 'trunk';

    /**
     * @param array<string, bool>                                       $canonical   move id => canonical
     * @param array<string, int>                                        $depth       position id => ply on the canonical path
     * @param array<string, string|null>                                $segmentOf   move id => segment key (null: not tested)
     * @param array<string, array{moveCount: int, userMoveCount: int}>  $segments    segment key => counts
     * @param array<string, true>                                       $tested      positions reached through tested moves
     */
    public function __construct(
        public array $canonical,
        public array $depth,
        public array $segmentOf,
        public array $segments,
        public array $tested,
    ) {
    }
}
