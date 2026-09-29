<?php

declare(strict_types=1);

namespace App\Activity\Log;

use App\Activity\Event\ExerciseCompleted;
use App\Repository\UserRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Writes the activity log (append-only). Idempotent: the unique (source_type, source_id) key turns
 * a second write of the same exercise (redelivered message, re-run backfill) into a no-op.
 */
final class ActivityLogger
{
    public function __construct(
        private readonly Connection $connection,
        private readonly UserRepository $users,
    ) {
    }

    /**
     * @return bool false when the exercise was already logged, or its user no longer exists
     */
    public function record(ExerciseCompleted $event): bool
    {
        if (!Uuid::isValid($event->userId)) {
            return false;
        }
        $user = $this->users->find(Uuid::fromString($event->userId));
        if (null === $user) {
            return false;
        }

        $timezone = $user->getDateTimeZone();
        $occurredAt = $event->occurredAt->setTimezone(new \DateTimeZone('UTC'));

        // ON DUPLICATE KEY UPDATE id = id: a no-op on the unique source key (INSERT IGNORE would
        // also silence unrelated errors). MySQL reports 1 affected row for an insert, 0 otherwise.
        $affected = $this->connection->executeStatement(
            'INSERT INTO activity_log_entry
                (id, user_id, exercise_type, success, duration_ms, item_count, source_type, source_id,
                 occurred_at, local_date, timezone, metadata)
             VALUES (:id, :user, :type, :success, :duration, :items, :sourceType, :sourceId,
                 :occurredAt, :localDate, :timezone, :metadata)
             ON DUPLICATE KEY UPDATE id = id',
            [
                'id' => Uuid::v7()->toBinary(),
                'user' => $user->getId()->toBinary(),
                'type' => $event->type->value,
                'success' => (int) $event->success,
                'duration' => max(0, $event->durationMs),
                'items' => max(0, $event->itemCount),
                'sourceType' => $event->sourceType,
                'sourceId' => $event->sourceId,
                'occurredAt' => $occurredAt->format('Y-m-d H:i:s'),
                'localDate' => LocalDate::of($occurredAt, $timezone)->format('Y-m-d'),
                'timezone' => $timezone->getName(),
                'metadata' => json_encode($event->metadata, \JSON_THROW_ON_ERROR),
            ],
        );

        return 1 === $affected;
    }
}
