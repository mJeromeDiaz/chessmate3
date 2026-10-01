<?php

declare(strict_types=1);

namespace App\Repertoire\Import\Message;

/**
 * Analyse a big import in a worker ({@see AnalyzeImportHandler}).
 */
final readonly class AnalyzeImport
{
    public function __construct(public string $importId)
    {
    }
}
