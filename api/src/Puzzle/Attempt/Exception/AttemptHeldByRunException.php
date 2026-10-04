<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt\Exception;

/**
 * The user's pending rated attempt belongs to an active timed run: it is played there, not in
 * free play (docs/TRAINING.md).
 */
final class AttemptHeldByRunException extends \RuntimeException
{
}
