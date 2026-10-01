<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

use App\Enum\Repertoire\MoveRole;

/**
 * A move of a {@see Graph}, with its stored derived fields (canonical, segment).
 */
final class GraphMove
{
    public function __construct(
        public readonly string $id,
        public readonly string $from,
        public readonly string $to,
        public MoveRole $role,
        public int $sortOrder = 0,
        public bool $canonical = false,
        public ?string $segmentId = null,
        public readonly string $san = '',
    ) {
    }
}
