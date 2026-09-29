<?php

declare(strict_types=1);

namespace App\Activity\Handler;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\Log\ActivityLogger;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Subscriber of the common event: feeds the activity log.
 */
#[AsMessageHandler]
final class LogExerciseCompleted
{
    public function __construct(private readonly ActivityLogger $logger)
    {
    }

    public function __invoke(ExerciseCompleted $event): void
    {
        $this->logger->record($event);
    }
}
