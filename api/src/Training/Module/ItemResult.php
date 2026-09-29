<?php

declare(strict_types=1);

namespace App\Training\Module;

use App\Enum\Training\CloseReason;

/**
 * The server's verdict on an item. $closes asks the runner to close the run now (e.g. the set is
 * finished), with $context explaining why (e.g. when the subject is playable again).
 */
final readonly class ItemResult
{
    /**
     * @param array<string, mixed> $data    module-specific details (status, mistakes...)
     * @param array<string, mixed> $context
     */
    public function __construct(
        public string $itemId,
        public bool $success,
        public array $data,
        public ?CloseReason $closes = null,
        public array $context = [],
    ) {
    }
}
