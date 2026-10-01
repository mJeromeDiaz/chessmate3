<?php

declare(strict_types=1);

namespace App\Repertoire\Graph;

/**
 * A position of a {@see Graph}.
 */
final class GraphPosition
{
    public function __construct(
        public readonly string $id,
        /** 'w' or 'b' */
        public readonly string $turn,
        public int $depth = 0,
    ) {
    }
}
