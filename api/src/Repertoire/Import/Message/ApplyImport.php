<?php

declare(strict_types=1);

namespace App\Repertoire\Import\Message;

/**
 * Apply a big import in a worker ({@see ApplyImportHandler}), as the user asked it (stored with
 * the import).
 */
final readonly class ApplyImport
{
    public function __construct(public string $importId)
    {
    }
}
