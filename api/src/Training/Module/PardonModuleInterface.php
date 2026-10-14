<?php

declare(strict_types=1);

namespace App\Training\Module;

use App\Entity\Training\Run;
use App\Training\Exception\AidRefusedException;
use App\Training\Exception\ItemNotFoundException;

/**
 * A module whose items take a pardon of the store during the run (blindfold puzzles,
 * docs/BLINDFOLD.md): asked on the item on screen, or on the one just resolved.
 */
interface PardonModuleInterface
{
    /**
     * Spends a pardon on an item of the run, in the runner's transaction.
     *
     * @return array<string, mixed> what the client shows (the verdict, the stock left...)
     *
     * @throws ItemNotFoundException
     * @throws AidRefusedException
     */
    public function pardon(Run $run, string $itemId, \DateTimeImmutable $now): array;
}
