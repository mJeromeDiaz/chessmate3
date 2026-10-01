<?php

declare(strict_types=1);

namespace App\Repertoire\Exception;

/**
 * The repertoire prepares no move of the user in this position: nothing to answer there.
 */
final class NoPreparedMoveException extends \RuntimeException
{
}
