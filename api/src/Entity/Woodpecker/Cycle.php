<?php

declare(strict_types=1);

namespace App\Entity\Woodpecker;

use App\Enum\Woodpecker\CycleStatus;
use App\Repository\Woodpecker\CycleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One run of one cycle of a set. A cycle whose deadline passes is lost, and a new run of the same
 * cycle number starts (validated rule): the set only moves on by completing a cycle in time.
 * Deadlines are UTC instants computed in the user's timezone ({@see \App\Woodpecker\Schedule\DeadlineCalculator}).
 */
#[ORM\Entity(repositoryClass: CycleRepository::class)]
#[ORM\Table(name: 'woodpecker_cycle')]
#[ORM\UniqueConstraint(name: 'uniq_woodpecker_cycle_set_number_run', columns: ['set_id', 'number', 'run'])]
#[ORM\Index(name: 'idx_woodpecker_cycle_set_status', columns: ['set_id', 'status'])]
#[ORM\Index(name: 'idx_woodpecker_cycle_set', columns: ['set_id'])]
class Cycle
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Set::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Set $set;

    /** 1-based cycle number. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $number;

    /** 1-based run of this cycle number (a lost run is followed by the next one). */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $run;

    #[ORM\Column(length: 16, enumType: CycleStatus::class)]
    private CycleStatus $status;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $durationDays;

    /** Order of the puzzles in this run (identity when the set is not shuffled). */
    #[ORM\Column(options: ['unsigned' => true])]
    private int $seed;

    #[ORM\Column]
    private \DateTimeImmutable $availableAt;

    /** Exclusive: the run must be completed before this instant (end of a local day). */
    #[ORM\Column]
    private \DateTimeImmutable $deadlineAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lostAt = null;

    public function __construct(Set $set, int $number, int $run, int $durationDays, int $seed, \DateTimeImmutable $availableAt, \DateTimeImmutable $deadlineAt, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->set = $set;
        $this->number = $number;
        $this->run = $run;
        $this->durationDays = $durationDays;
        $this->seed = $seed;
        $this->availableAt = $availableAt;
        $this->deadlineAt = $deadlineAt;
        $this->status = $availableAt > $now ? CycleStatus::Resting : CycleStatus::Active;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSet(): Set
    {
        return $this->set;
    }

    public function getNumber(): int
    {
        return $this->number;
    }

    public function getRun(): int
    {
        return $this->run;
    }

    public function getStatus(): CycleStatus
    {
        return $this->status;
    }

    public function isOpen(): bool
    {
        return CycleStatus::Resting === $this->status || CycleStatus::Active === $this->status;
    }

    public function getDurationDays(): int
    {
        return $this->durationDays;
    }

    public function getSeed(): int
    {
        return $this->seed;
    }

    public function getAvailableAt(): \DateTimeImmutable
    {
        return $this->availableAt;
    }

    public function getDeadlineAt(): \DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getLostAt(): ?\DateTimeImmutable
    {
        return $this->lostAt;
    }

    public function activate(): void
    {
        if (CycleStatus::Resting === $this->status) {
            $this->status = CycleStatus::Active;
        }
    }

    public function complete(\DateTimeImmutable $at): void
    {
        $this->assertActive();
        $this->status = CycleStatus::Completed;
        $this->completedAt = $at;
    }

    public function lose(\DateTimeImmutable $at): void
    {
        $this->assertActive();
        $this->status = CycleStatus::Lost;
        $this->lostAt = $at;
    }

    /**
     * After a pause: the dates move by the pause length (already snapped by the calculator).
     */
    public function reschedule(\DateTimeImmutable $availableAt, \DateTimeImmutable $deadlineAt): void
    {
        $this->availableAt = $availableAt;
        $this->deadlineAt = $deadlineAt;
    }

    private function assertActive(): void
    {
        if (CycleStatus::Active !== $this->status) {
            throw new \DomainException(sprintf('The cycle is %s.', $this->status->value));
        }
    }
}
