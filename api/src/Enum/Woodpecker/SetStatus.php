<?php

declare(strict_types=1);

namespace App\Enum\Woodpecker;

enum SetStatus: string
{
    case Active = 'active';
    case Paused = 'paused';
    case Completed = 'completed';
    case Abandoned = 'abandoned';

    /** Active and paused sets count against the one-set-at-a-time rule. */
    public function isOngoing(): bool
    {
        return self::Active === $this || self::Paused === $this;
    }
}
