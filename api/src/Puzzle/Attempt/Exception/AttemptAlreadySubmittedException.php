<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt\Exception;

/**
 * The attempt was already resolved: a result is submitted once.
 */
final class AttemptAlreadySubmittedException extends \RuntimeException
{
}
