<?php

declare(strict_types=1);

namespace App\Woodpecker\Exception;

/**
 * No puzzle can be played right now: the set is paused or closed, or the cycle is still resting.
 */
final class SetNotPlayableException extends \RuntimeException
{
    public const PAUSED = 'paused';
    public const CLOSED = 'closed';
    public const RESTING = 'resting';

    public function __construct(public readonly string $reason, public readonly ?\DateTimeImmutable $availableAt = null)
    {
        parent::__construct(sprintf('Set not playable: %s.', $reason));
    }
}
