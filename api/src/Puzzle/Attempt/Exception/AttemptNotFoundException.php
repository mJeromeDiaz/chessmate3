<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt\Exception;

/**
 * No such attempt for this user (another user's attempt is reported the same way).
 */
final class AttemptNotFoundException extends \RuntimeException
{
}
