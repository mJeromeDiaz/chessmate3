<?php

declare(strict_types=1);

namespace App\Training\Exception;

use App\Enum\Training\CloseReason;

/**
 * The subject cannot be played (any more) in this run: $reason is the run's close reason,
 * $context explains it (e.g. availableAt for a resting cycle, the subject's status).
 */
final class SubjectUnavailableException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(public readonly CloseReason $reason, public readonly array $context = [], string $message = 'The subject cannot be played now.')
    {
        parent::__construct($message);
    }
}
