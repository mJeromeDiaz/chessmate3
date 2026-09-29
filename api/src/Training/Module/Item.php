<?php

declare(strict_types=1);

namespace App\Training\Module;

/**
 * An element served by a module during a run: its id (to submit it) and module-specific data
 * (e.g. a puzzle), plain scalars and arrays.
 */
final readonly class Item
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        public string $id,
        public string $type,
        public array $data,
    ) {
    }
}
