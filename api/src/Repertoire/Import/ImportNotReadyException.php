<?php

declare(strict_types=1);

namespace App\Repertoire\Import;

/**
 * The import cannot be applied in its state: still being analysed, being applied, done or failed.
 */
final class ImportNotReadyException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('This import is not ready to be applied.');
    }
}
