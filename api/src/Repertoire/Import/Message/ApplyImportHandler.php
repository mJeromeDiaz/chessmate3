<?php

declare(strict_types=1);

namespace App\Repertoire\Import\Message;

use App\Repertoire\Import\ImportService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Uid\Uuid;

/**
 * Idempotent: an import already applied (or gone) is left as it is.
 */
#[AsMessageHandler]
final readonly class ApplyImportHandler
{
    public function __construct(private ImportService $imports)
    {
    }

    public function __invoke(ApplyImport $message): void
    {
        $this->imports->runApplication(Uuid::fromString($message->importId));
    }
}
