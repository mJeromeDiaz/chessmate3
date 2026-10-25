<?php

declare(strict_types=1);

namespace App\Entity\MindMap;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\MindMap\AttemptOutcome;
use App\Enum\MindMap\Capacity;
use App\Repository\MindMap\AttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A run played for a step of a mind map path (docs/MIND_MAP.md, § 4), judged when it closes.
 */
#[ORM\Entity(repositoryClass: AttemptRepository::class)]
#[ORM\Table(name: 'mind_map_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_mind_map_attempt_run', columns: ['run_id'])]
#[ORM\Index(name: 'idx_mind_map_attempt_user_step', columns: ['user_id', 'capacity', 'step'])]
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

    #[ORM\Column(length: 16, enumType: Capacity::class)]
    private Capacity $capacity;

    #[ORM\Column(length: 40, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $step;

    #[ORM\Column(length: 8, enumType: AttemptOutcome::class)]
    private AttemptOutcome $outcome = AttemptOutcome::Pending;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $itemCount = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $successCount = 0;

    /** What its module counted as failures (a recall's pieces wrong or missing), null: its items failed. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $failures = null;

    /** Its pass completed the step (the last pass, the boss beaten). */
    #[ORM\Column(options: ['default' => false])]
    private bool $completed = false;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $judgedAt = null;

    public function __construct(Run $run, Capacity $capacity, string $step, \DateTimeImmutable $startedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->capacity = $capacity;
        $this->step = $step;
        $this->startedAt = $startedAt;
    }

    /**
     * @param bool $assisted passed with a help that makes it not count (then not $completed)
     */
    public function judge(bool $passed, int $itemCount, int $successCount, bool $completed, \DateTimeImmutable $at, ?int $failures = null, bool $assisted = false): void
    {
        if (AttemptOutcome::Pending !== $this->outcome) {
            throw new \LogicException('Attempt already judged.');
        }
        $this->outcome = match (true) {
            !$passed => AttemptOutcome::Failed,
            $assisted => AttemptOutcome::Assisted,
            default => AttemptOutcome::Passed,
        };
        $this->itemCount = min($itemCount, 65_535);
        $this->successCount = min($successCount, 65_535);
        $this->failures = null === $failures ? null : min(max(0, $failures), 65_535);
        $this->completed = $completed;
        $this->judgedAt = $at;
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

    public function getCapacity(): Capacity
    {
        return $this->capacity;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function getOutcome(): AttemptOutcome
    {
        return $this->outcome;
    }

    public function getItemCount(): int
    {
        return $this->itemCount;
    }

    public function getSuccessCount(): int
    {
        return $this->successCount;
    }

    /** The failures that count against a boss's lives. */
    public function getFailures(): int
    {
        return $this->failures ?? max(0, $this->itemCount - $this->successCount);
    }

    public function isCompleted(): bool
    {
        return $this->completed;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getJudgedAt(): ?\DateTimeImmutable
    {
        return $this->judgedAt;
    }
}
