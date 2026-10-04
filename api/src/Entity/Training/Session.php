<?php

declare(strict_types=1);

namespace App\Entity\Training;

use App\Entity\User;
use App\Enum\Training\Module;
use App\Enum\Training\SessionStatus;
use App\Enum\Training\StepStatus;
use App\Repository\Training\SessionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A training session (docs/TRAINING.md): an ordered program of modules frozen at launch, played
 * step by step, each step being a timed run whose parent_id is the session. It belongs to the
 * local day it was launched on: past that day it can no longer go on (closed lazily).
 *
 * `activeUserId` is a generated column (user_id while active, NULL otherwise) under a unique
 * index: one active session per user, enforced by the database (VIRTUAL, as training_run).
 *
 * @phpstan-type StepData array{module: string, minutes: int, notes: string, settings: array<string, mixed>, status: string, runId: string|null, blocked: array{reason: string, message: string}|null}
 */
#[ORM\Entity(repositoryClass: SessionRepository::class)]
#[ORM\Table(name: 'training_session')]
#[ORM\UniqueConstraint(name: 'uniq_training_session_active_user', columns: ['active_user_id'])]
#[ORM\Index(name: 'idx_training_session_user_started', columns: ['user_id', 'started_at'])]
#[ORM\Index(name: 'idx_training_session_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_training_session_plan', columns: ['plan_id'])]
class Session
{
    public const MAX_STEPS = 10;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 120)]
    private string $title;

    #[ORM\Column(length: 500)]
    private string $description;

    /** @var list<StepData> */
    #[ORM\Column(type: Types::JSON)]
    private array $steps;

    /** The step being played or next to play; the step count once every step is over. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $currentIndex = 0;

    #[ORM\Column(length: 16, enumType: SessionStatus::class)]
    private SessionStatus $status = SessionStatus::Active;

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

    /** The end of the local day it was launched on (UTC instant). */
    #[ORM\Column]
    private \DateTimeImmutable $expiresAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $closedAt = null;

    /** The saved session it was launched from, if any (set to NULL when that plan is deleted). */
    #[ORM\ManyToOne(targetEntity: Plan::class)]
    #[ORM\JoinColumn(name: 'plan_id', nullable: true, onDelete: 'SET NULL')]
    private ?Plan $plan = null;

    /**
     * @param list<array{module: Module, minutes: int, notes: string, settings: array<string, mixed>}> $steps
     */
    public function __construct(User $user, string $title, string $description, array $steps, \DateTimeImmutable $startedAt, \DateTimeImmutable $expiresAt, ?Plan $plan = null)
    {
        if ([] === $steps || \count($steps) > self::MAX_STEPS) {
            throw new \InvalidArgumentException(sprintf('A session has 1 to %d steps.', self::MAX_STEPS));
        }
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->title = $title;
        $this->description = $description;
        $this->steps = array_map(static fn (array $step): array => [
            'module' => $step['module']->value,
            'minutes' => $step['minutes'],
            'notes' => $step['notes'],
            'settings' => $step['settings'],
            'status' => StepStatus::Pending->value,
            'runId' => null,
            'blocked' => null,
        ], $steps);
        $this->startedAt = $startedAt;
        $this->expiresAt = $expiresAt;
        $this->plan = $plan;
    }

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    /**
     * @return list<StepData>
     */
    public function getSteps(): array
    {
        return $this->steps;
    }

    public function getCurrentIndex(): int
    {
        return $this->currentIndex;
    }

    /**
     * @return StepData|null the step being played or next to play
     */
    public function currentStep(): ?array
    {
        return $this->steps[$this->currentIndex] ?? null;
    }

    public function currentModule(): ?Module
    {
        $step = $this->currentStep();

        return null === $step ? null : Module::from($step['module']);
    }

    public function currentStatus(): ?StepStatus
    {
        $step = $this->currentStep();

        return null === $step ? null : StepStatus::from($step['status']);
    }

    public function currentRunId(): ?Uuid
    {
        $id = $this->currentStep()['runId'] ?? null;

        return null === $id ? null : Uuid::fromString($id);
    }

    public function getStatus(): SessionStatus
    {
        return $this->status;
    }

    public function isActive(): bool
    {
        return SessionStatus::Active === $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getExpiresAt(): \DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function getClosedAt(): ?\DateTimeImmutable
    {
        return $this->closedAt;
    }

    /**
     * The current step's run started.
     */
    public function startCurrent(Uuid $runId): void
    {
        $this->setCurrent(['status' => StepStatus::Running->value, 'runId' => $runId->toRfc4122(), 'blocked' => null]);
    }

    /**
     * The current step could not start: why, until the user tries again or passes it.
     */
    public function blockCurrent(string $reason, string $message): void
    {
        $this->setCurrent(['blocked' => ['reason' => $reason, 'message' => $message]]);
    }

    /**
     * The current step's run is closed: the next step becomes current.
     */
    public function finishCurrent(): void
    {
        $this->setCurrent(['status' => StepStatus::Done->value, 'blocked' => null]);
        ++$this->currentIndex;
    }

    public function skipCurrent(): void
    {
        $this->setCurrent(['status' => StepStatus::Skipped->value]);
        ++$this->currentIndex;
    }

    public function isOver(): bool
    {
        return $this->currentIndex >= \count($this->steps);
    }

    /**
     * Ends the session; steps not reached are left unplayed.
     */
    public function close(SessionStatus $status, \DateTimeImmutable $closedAt): void
    {
        if (SessionStatus::Active === $status || !$this->isActive()) {
            throw new \LogicException('Only an active session closes, to a final status.');
        }
        foreach ($this->steps as $i => $step) {
            if (StepStatus::Pending->value === $step['status']) {
                $this->steps[$i]['status'] = StepStatus::Unplayed->value;
            }
        }
        $this->status = $status;
        $this->closedAt = $closedAt;
    }

    /**
     * @param array<string, mixed> $changes
     */
    private function setCurrent(array $changes): void
    {
        $step = $this->currentStep() ?? throw new \LogicException('No current step.');
        /** @var StepData $updated */
        $updated = array_replace($step, $changes);
        $this->steps[$this->currentIndex] = $updated;
    }
}
