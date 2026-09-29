<?php

declare(strict_types=1);

namespace App\Woodpecker\Exception;

/**
 * No puzzle can be played right now: the set is paused or closed, the cycle is still resting, a
 * light set is played in timed runs only, or a timed run holds the set (or the attempt).
 */
final class SetNotPlayableException extends \RuntimeException
{
    public const PAUSED = 'paused';
    public const CLOSED = 'closed';
    public const RESTING = 'resting';
    public const TIMED_ONLY = 'timed_only';
    public const IN_RUN = 'in_run';

    public function __construct(public readonly string $reason, public readonly ?\DateTimeImmutable $availableAt = null)
    {
        parent::__construct(sprintf('Set not playable: %s.', $reason));
    }
}
