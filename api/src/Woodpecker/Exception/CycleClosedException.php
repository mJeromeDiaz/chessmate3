<?php

declare(strict_types=1);

namespace App\Woodpecker\Exception;

/**
 * The attempt's cycle run is over (completed or lost): it can no longer be submitted.
 */
final class CycleClosedException extends \RuntimeException
{
}
