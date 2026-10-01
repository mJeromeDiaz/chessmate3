<?php

declare(strict_types=1);

namespace App\Repertoire\Exception;

/**
 * The move prepared in this position is no longer the one the question was asked for (the
 * repertoire changed meanwhile): the answer is not graded.
 */
final class PreparedMoveChangedException extends \RuntimeException
{
}
