<?php

declare(strict_types=1);

namespace App\Repertoire\Exception;

/**
 * The move is not legal (or not UCI) in its position.
 */
final class IllegalMoveException extends \InvalidArgumentException
{
}
