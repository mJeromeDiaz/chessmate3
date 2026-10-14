<?php

declare(strict_types=1);

namespace App\Training\Module;

use App\Entity\Training\Run;
use App\Training\Exception\AidRefusedException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemNotFoundException;

/**
 * A module whose items take a paid help of the store (the puzzle streak, docs/PUZZLE_STREAK.md):
 * spent on the server when asked, so the submission may then use it.
 */
interface HelpModuleInterface
{
    /**
     * Spends a help on the item on screen, in the runner's transaction.
     *
     * @return array<string, mixed> what the client shows (the stock left...)
     *
     * @throws ItemNotFoundException
     * @throws ItemAlreadySubmittedException
     * @throws AidRefusedException
     */
    public function help(Run $run, string $itemId, \DateTimeImmutable $now): array;
}
