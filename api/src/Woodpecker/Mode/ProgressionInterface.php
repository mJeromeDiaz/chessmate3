<?php

declare(strict_types=1);

namespace App\Woodpecker\Mode;

use App\Entity\Woodpecker\Cycle;
use App\Entity\Woodpecker\Set;
use App\Enum\Woodpecker\SetMode;
use App\Woodpecker\Exception\SetNotPlayableException;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * How a set of one mode moves on (docs/WOODPECKER.md). {@see \App\Woodpecker\Cycle\CycleRunner}
 * keeps what every mode shares (locks, attempts, server-side validation, ExerciseCompleted) and
 * delegates the rest here. Every method runs inside the caller's transaction, set locked; none
 * flushes.
 */
#[AutoconfigureTag(self::TAG)]
interface ProgressionInterface
{
    public const TAG = 'app.woodpecker.progression';

    public function mode(): SetMode;

    /**
     * Opens the first round of a new set (already persisted).
     */
    public function start(Set $set, \DateTimeImmutable $now): void;

    /**
     * Applies the time-driven transitions due at $now to the open round of an active set.
     */
    public function refresh(Set $set, Cycle $open, \DateTimeImmutable $now): void;

    /**
     * @throws SetNotPlayableException when the open round cannot be played yet
     */
    public function assertPlayable(Set $set, Cycle $open): void;

    /**
     * The set position of the $index-th puzzle (0-based) of the round, null past its end.
     */
    public function positionAt(Set $set, Cycle $round, int $index): ?int;

    /**
     * After an attempt of the round was resolved and flushed: closes the round when it is over,
     * and whatever follows (next round, end of the set).
     */
    public function afterSubmission(Set $set, Cycle $round, \DateTimeImmutable $now): void;

    /**
     * After a paused set was resumed ($pausedAt: when the pause began).
     */
    public function resume(Set $set, \DateTimeImmutable $pausedAt, \DateTimeImmutable $now): void;
}
