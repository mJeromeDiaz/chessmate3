<?php

declare(strict_types=1);

namespace App\Puzzle\Selection;

/**
 * A themed draw while the selection index is being rebuilt (a minute or two, after each catalogue
 * import): the caller should retry shortly. Answered as a 503 by
 * {@see \App\EventListener\PuzzleMaintenanceListener}.
 */
final class SelectionUnavailableException extends \RuntimeException
{
    public const RETRY_AFTER = 60;

    public function __construct()
    {
        parent::__construct('The puzzle selection index is being rebuilt.');
    }
}
