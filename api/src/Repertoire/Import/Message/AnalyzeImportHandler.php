<?php

declare(strict_types=1);

namespace App\Repertoire\Import\Message;

use App\Repertoire\Import\ImportService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Idempotent: an import already analysed (or gone) is left as it is.
 */
#[AsMessageHandler]
final readonly class AnalyzeImportHandler
{
    public function __construct(private ImportService $imports)
    {
    }

    public function __invoke(AnalyzeImport $message): void
    {
        $this->imports->runAnalysis(Uuid::fromString($message->importId));
    }
}
