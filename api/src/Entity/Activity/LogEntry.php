<?php

declare(strict_types=1);

namespace App\Entity\Activity;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\Log\LocalDate;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Repository\Activity\LogEntryRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One completed exercise in the user's activity log (docs/ACTIVITY.md). Append-only: written once
 * by {@see \App\Activity\Log\ActivityLogger} (read-only entity, the ORM never updates it), never
 * deleted by the application (only with the user account, by cascade).
 *
 * `localDate` is the day in the user's timezone *at write time* and never changes afterwards:
 * streaks computed on it are not rewritten when the user moves.
 */
#[ORM\Entity(repositoryClass: LogEntryRepository::class, readOnly: true)]
#[ORM\Table(name: 'activity_log_entry')]
#[ORM\UniqueConstraint(name: 'uniq_activity_log_entry_source', columns: ['source_type', 'source_id'])]
#[ORM\Index(name: 'idx_activity_log_entry_user_date', columns: ['user_id', 'local_date'])]
#[ORM\Index(name: 'idx_activity_log_entry_user_type_date', columns: ['user_id', 'exercise_type', 'local_date'])]
#[ORM\Index(name: 'idx_activity_log_entry_user', columns: ['user_id'])]
class LogEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, enumType: ExerciseType::class)]
    private ExerciseType $exerciseType;

    #[ORM\Column]
    private bool $success;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $durationMs;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $itemCount;

    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $sourceType;

    #[ORM\Column(length: 64, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $sourceId;

    /** UTC. */
    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::DATE_IMMUTABLE)]
    private \DateTimeImmutable $localDate;

    /** The IANA timezone $localDate was computed with. */
    #[ORM\Column(length: 64)]
    private string $timezone;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $metadata;

    /**
     * The same row {@see \App\Activity\Log\ActivityLogger} writes in SQL (for its idempotent
     * insert); kept for fixtures and tests.
     */
    public function __construct(User $user, ExerciseCompleted $event)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->exerciseType = $event->type;
        $this->success = $event->success;
        $this->durationMs = max(0, $event->durationMs);
        $this->itemCount = max(0, $event->itemCount);
        $this->sourceType = $event->sourceType;
        $this->sourceId = $event->sourceId;
        $this->occurredAt = $event->occurredAt->setTimezone(new \DateTimeZone('UTC'));
        $timezone = $user->getDateTimeZone();
        $this->localDate = LocalDate::of($this->occurredAt, $timezone);
        $this->timezone = $timezone->getName();
        $this->metadata = $event->metadata;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getExerciseType(): ExerciseType
    {
        return $this->exerciseType;
    }

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getDurationMs(): int
    {
        return $this->durationMs;
    }

    public function getItemCount(): int
    {
        return $this->itemCount;
    }

    public function getSourceType(): string
    {
        return $this->sourceType;
    }

    public function getSourceId(): string
    {
        return $this->sourceId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function getLocalDate(): \DateTimeImmutable
    {
        return $this->localDate;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    /**
     * @return array<string, mixed>
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
}
