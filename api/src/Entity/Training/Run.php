<?php

declare(strict_types=1);

namespace App\Entity\Training;

use App\Entity\User;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Enum\Training\RunStatus;
use App\Repository\Training\RunRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One timed run of one module (docs/TRAINING.md): a time budget counted by the server, on a
 * subject owned by the module (e.g. a Woodpecker set). Instants are UTC.
 *
 * `activeUserId` is a generated column (user_id while active, NULL otherwise) under a unique
 * index: one active run per user, enforced by the database. VIRTUAL for the same reason as
 * woodpecker_set (ON DELETE CASCADE foreign key on user_id).
 */
#[ORM\Entity(repositoryClass: RunRepository::class)]
#[ORM\Table(name: 'training_run')]
#[ORM\UniqueConstraint(name: 'uniq_training_run_active_user', columns: ['active_user_id'])]
#[ORM\Index(name: 'idx_training_run_user_started', columns: ['user_id', 'started_at'])]
#[ORM\Index(name: 'idx_training_run_subject', columns: ['subject_type', 'subject_id', 'started_at'])]
#[ORM\Index(name: 'idx_training_run_user', columns: ['user_id'])]
class Run
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 32, enumType: Module::class)]
    private Module $module;

    /** What the subject id refers to, named by the module (e.g. woodpecker_set). */
    #[ORM\Column(length: 32)]
    private string $subjectType;

    #[ORM\Column(type: 'uuid')]
    private Uuid $subjectId;

    /** @var array<string, mixed> module-specific options */
    #[ORM\Column(type: Types::JSON)]
    private array $config;

    #[ORM\Column(options: ['unsigned' => true])]
    private int $budgetSeconds;

    #[ORM\Column(length: 16, enumType: RunStatus::class)]
    private RunStatus $status = RunStatus::Active;

    #[ORM\Column(
        type: 'uuid',
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: "BINARY(16) GENERATED ALWAYS AS (IF(status = 'active', user_id, NULL)) VIRTUAL",
        generated: 'ALWAYS',
    )]
    private ?Uuid $activeUserId = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    /** No item is served from this instant on. */
    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    #[ORM\Column(length: 24, nullable: true, enumType: CloseReason::class)]
    private ?CloseReason $closeReason = null;

    /** @var array<string, mixed>|null normalized recap, written at closing ({@see \App\Training\Module\Summary}) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $summary = null;

    /** The multi-module session this run belongs to (Phase 6; no such table yet). */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $parentId = null;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(User $user, Module $module, string $subjectType, Uuid $subjectId, int $budgetSeconds, array $config, \DateTimeImmutable $startedAt, ?Uuid $parentId = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->module = $module;
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;
        $this->budgetSeconds = $budgetSeconds;
        $this->config = $config;
        $this->startedAt = $startedAt;
        $this->expiresAt = $startedAt->modify(sprintf('+%d seconds', $budgetSeconds));
        $this->parentId = $parentId;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getModule(): Module
    {
        return $this->module;
    }

    public function getSubjectType(): string
    {
        return $this->subjectType;
    }

    public function getSubjectId(): Uuid
    {
        return $this->subjectId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function getBudgetSeconds(): int
    {
        return $this->budgetSeconds;
    }

    public function getStatus(): RunStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return RunStatus::Active === $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now >= $this->expiresAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    public function getCloseReason(): ?CloseReason
    {
        return $this->closeReason;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getSummary(): ?array
    {
        return $this->summary;
    }

    public function getParentId(): ?Uuid
    {
        return $this->parentId;
    }

    /**
     * @param array<string, mixed> $summary
     */
    public function close(CloseReason $reason, \DateTimeImmutable $closedAt, array $summary): void
    {
        if (!$this->isActive()) {
            throw new \DomainException('The run is already closed.');
        }
        $this->status = RunStatus::Closed;
        $this->closeReason = $reason;
        $this->closedAt = $closedAt;
        $this->summary = $summary;
    }
}
