<?php

declare(strict_types=1);

namespace App\Training\Exception;

/**
 * The current step's run is in progress: it cannot be skipped (stop the run first).
 */
final class StepRunningException extends \RuntimeException
{
}
