<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * The current step cannot start now: $reason (e.g. no_light_set, nothing_to_test) and a message.
 * The step stays current, marked blocked: the user tries again, passes it or abandons.
 */
final class StepBlockedException extends \RuntimeException
{
    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
