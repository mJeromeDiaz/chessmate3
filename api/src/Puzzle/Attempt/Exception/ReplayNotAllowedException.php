<?php

declare(strict_types=1);

namespace App\Puzzle\Attempt\Exception;

/**
 * Only puzzles from the user's own history can be replayed.
 */
final class ReplayNotAllowedException extends \RuntimeException
{
}
