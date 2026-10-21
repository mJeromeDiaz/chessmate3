<?php

declare(strict_types=1);

namespace App\Training\Module;

use App\Entity\Training\Run;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;

/**
 * A module whose items are thought over first, then answered against a clock that starts when the
 * player says so (the calculation module, docs/CALCULATION.md: « Je réponds »): the server stamps
 * that moment, so that the answer's time is its own.
 */
interface ReadyModuleInterface
{
    /**
     * Stamps the item on screen as ready to be answered, in the runner's transaction. Idempotent.
     *
     * @return array<string, mixed> what the client shows (when the answer's clock started)
     *
     * @throws ItemNotFoundException
     * @throws ItemAlreadySubmittedException
     */
    public function ready(Run $run, string $itemId, \DateTimeImmutable $now): array;
}
