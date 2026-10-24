<?php

declare(strict_types=1);

namespace App\Shop\Wheel;

/**
 * A spin refused before any write (docs/SHOP.md, § 4 ter): the API answers 409 with the reason.
 */
final class WheelRefusal extends \RuntimeException
{
    /** No wheel of the day to spin: spun, declined, off, another day's, or not the player's. */
    public const NO_SPIN = 'no_spin';
    /** No ticket to spin once more. */
    public const NO_TICKET = 'no_ticket';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}
