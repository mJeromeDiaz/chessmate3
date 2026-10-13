<?php

declare(strict_types=1);

namespace App\Coach\Session;

/**
 * A refusal of the tailored session (docs/COACH.md), with its reason for the API.
 */
final class CoachException extends \RuntimeException
{
    /** The games are still being read. */
    public const NOT_READY = 'not_ready';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
