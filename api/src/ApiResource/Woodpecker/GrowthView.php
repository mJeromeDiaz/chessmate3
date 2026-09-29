<?php

declare(strict_types=1);

namespace App\ApiResource\Woodpecker;

use App\Entity\Woodpecker\Growth;

/**
 * One growth in a light set's history.
 */
final class GrowthView
{
    public \DateTimeImmutable $occurredAt;
    /** Number of the round that ran out of puzzles. */
    public int $round;
    public int $added;
    /** Size of the set after this growth. */
    public int $puzzleCount;

    public static function from(Growth $growth): self
    {
        $view = new self();
        $view->occurredAt = $growth->getOccurredAt();
        $view->round = $growth->getRound();
        $view->added = $growth->getAdded();
        $view->puzzleCount = $growth->getPuzzleCount();

        return $view;
    }
}
