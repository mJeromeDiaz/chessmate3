<?php

declare(strict_types=1);

namespace App\Entity\Evaluation;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Evaluation\AttemptStatus;
use App\Enum\Evaluation\Plan;
use App\Repository\Evaluation\AttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A position served in a timed run (docs/EVALUATION.md), with its own deadline on the server's
 * clock, then judged: the player's category against the engine's, the plan, the time.
 */
#[ORM\Entity(repositoryClass: AttemptRepository::class)]
#[ORM\Table(name: 'evaluation_attempt')]
#[ORM\Index(name: 'idx_evaluation_attempt_run', columns: ['run_id', 'served_at'])]
#[ORM\Index(name: 'idx_evaluation_attempt_user_position', columns: ['user_id', 'position_id', 'served_at'])]
#[ORM\Index(name: 'idx_evaluation_attempt_user_submitted', columns: ['user_id', 'submitted_at'])]
class Attempt
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Run::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Run $run;

    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Position $position;

    #[ORM\Column]
    private \DateTimeImmutable $servedAt;

    /** Seconds the player has for it. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $seconds;

    #[ORM\Column(length: 8, enumType: AttemptStatus::class)]
    private AttemptStatus $status = AttemptStatus::Pending;

    /** The player's category (-2 to 2), null when no answer came in time. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true)]
    private ?int $guess = null;

    #[ORM\Column(length: 16, nullable: true, enumType: Plan::class)]
    private ?Plan $plan = null;

    #[ORM\Column(nullable: true)]
    private ?bool $planOk = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    /** Time taken, capped by the position's time. */
    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    public function __construct(Run $run, Position $position, int $seconds, \DateTimeImmutable $servedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->position = $position;
        $this->seconds = $seconds;
        $this->servedAt = $servedAt;
    }

    public function resolve(AttemptStatus $status, ?int $guess, ?Plan $plan, \DateTimeImmutable $at): void
    {
        if (AttemptStatus::Pending !== $this->status || AttemptStatus::Pending === $status) {
            throw new \LogicException('Attempt already resolved, or no verdict.');
        }
        $this->status = $status;
        $this->guess = $guess;
        $this->plan = $plan;
        // No plan chosen, or none to find: not judged.
        $this->planOk = null === $plan || null === $this->position->getPlan() ? null : $plan === $this->position->getPlan();
        $this->submittedAt = $at;
        $elapsed = (int) round(((float) $at->format('U.u') - (float) $this->servedAt->format('U.u')) * 1000);
        $this->durationMs = max(0, min($this->seconds * 1000, $elapsed));
    }

    /**
     * The instant after which no answer is on time (the network tolerance included).
     */
    public function deadline(int $toleranceMs): \DateTimeImmutable
    {
        return $this->servedAt->modify(\sprintf('+%d milliseconds', $this->seconds * 1000 + $toleranceMs));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRun(): Run
    {
        return $this->run;
    }

    public function getPosition(): Position
    {
        return $this->position;
    }

    public function getServedAt(): \DateTimeImmutable
    {
        return $this->servedAt;
    }

    public function getSeconds(): int
    {
        return $this->seconds;
    }

    public function getStatus(): AttemptStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return AttemptStatus::Pending === $this->status;
    }

    public function getGuess(): ?int
    {
        return $this->guess;
    }

    public function getPlan(): ?Plan
    {
        return $this->plan;
    }

    public function isPlanOk(): ?bool
    {
        return $this->planOk;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }
}
