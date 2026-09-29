<?php

declare(strict_types=1);

namespace App\Entity\Woodpecker;

use App\Entity\Puzzle\Puzzle;
use App\Entity\Training\Run;
use App\Enum\Puzzle\AttemptStatus;
use App\Repository\Woodpecker\AttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One puzzle played in one round (classic: cycle run). Never rated. `uniq (cycle_id, puzzle_id)`:
 * a puzzle is played once per round, enforced by the database. The outcome and the duration are
 * computed by the server, as in Phase 2. Played in a timed run, it references that run
 * ({@see Run}, per-run statistics and submission checks).
 */
#[ORM\Entity(repositoryClass: AttemptRepository::class)]
#[ORM\Table(name: 'woodpecker_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_woodpecker_attempt_cycle_puzzle', columns: ['cycle_id', 'puzzle_id'])]
#[ORM\Index(name: 'idx_woodpecker_attempt_cycle_status', columns: ['cycle_id', 'status'])]
#[ORM\Index(name: 'idx_woodpecker_attempt_puzzle', columns: ['puzzle_id'])]
#[ORM\Index(name: 'idx_woodpecker_attempt_cycle', columns: ['cycle_id'])]
#[ORM\Index(name: 'idx_woodpecker_attempt_training_run_status', columns: ['training_run_id', 'status'])]
#[ORM\Index(name: 'idx_woodpecker_attempt_training_run', columns: ['training_run_id'])]
class Attempt
{
    /** Longest duration counted as active time in the statistics (a tab left open overnight). */
    public const ACTIVE_TIME_CAP_MS = 300_000;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Cycle::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Cycle $cycle;

    #[ORM\ManyToOne(targetEntity: Puzzle::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Puzzle $puzzle;

    #[ORM\ManyToOne(targetEntity: Run::class)]
    #[ORM\JoinColumn(name: 'training_run_id', nullable: true, onDelete: 'SET NULL')]
    private ?Run $run;

    /** 0-based index in the run's order. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $orderIndex;

    #[ORM\Column(length: 10, enumType: AttemptStatus::class)]
    private AttemptStatus $status = AttemptStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $moves = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $mistakes = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $hintLevel = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $solutionShown = false;

    public function __construct(Cycle $cycle, Puzzle $puzzle, int $orderIndex, \DateTimeImmutable $startedAt, ?Run $run = null)
    {
        $this->id = Uuid::v7();
        $this->cycle = $cycle;
        $this->puzzle = $puzzle;
        $this->orderIndex = $orderIndex;
        $this->startedAt = $startedAt;
        $this->run = $run;
    }

    /**
     * A pending attempt served before this run (untimed play) is taken over by it, with a fresh
     * timer: its duration then counts within the run.
     */
    public function assignTo(Run $run, \DateTimeImmutable $now): void
    {
        if (AttemptStatus::Pending !== $this->status) {
            throw new \LogicException('Only a pending attempt moves to a run.');
        }
        $this->run = $run;
        $this->startedAt = $now;
    }

    public function getRun(): ?Run
    {
        return $this->run;
    }

    public function belongsTo(?Run $run): bool
    {
        return null === $run ? null === $this->run : null !== $this->run && $this->run->getId()->equals($run->getId());
    }

    /**
     * @param list<string> $moves
     */
    public function resolve(bool $solved, array $moves, int $mistakes, int $hintLevel, bool $solutionShown, \DateTimeImmutable $at): void
    {
        if (AttemptStatus::Pending !== $this->status) {
            throw new \LogicException('Attempt already resolved.');
        }
        $this->status = $solved ? AttemptStatus::Solved : AttemptStatus::Failed;
        $this->moves = $moves;
        $this->mistakes = $mistakes;
        $this->hintLevel = $hintLevel;
        $this->solutionShown = $solutionShown;
        $this->submittedAt = $at;
        $this->durationMs = max(0, (int) round(((float) $at->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000));
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getCycle(): Cycle
    {
        return $this->cycle;
    }

    public function getPuzzle(): Puzzle
    {
        return $this->puzzle;
    }

    public function getOrderIndex(): int
    {
        return $this->orderIndex;
    }

    public function getStatus(): AttemptStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return AttemptStatus::Pending === $this->status;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }

    /**
     * @return list<string>|null
     */
    public function getMoves(): ?array
    {
        return $this->moves;
    }

    public function getMistakes(): int
    {
        return $this->mistakes;
    }

    public function getHintLevel(): int
    {
        return $this->hintLevel;
    }

    public function isSolutionShown(): bool
    {
        return $this->solutionShown;
    }
}
