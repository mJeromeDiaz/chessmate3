<?php

declare(strict_types=1);

namespace App\Puzzle\Import;

/**
 * A line of the export that does not fit the `puzzle` table: the import skips it and reports it.
 */
final class InvalidLineException extends \RuntimeException
{
}
