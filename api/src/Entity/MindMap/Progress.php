<?php

declare(strict_types=1);

namespace App\Entity\MindMap;

use App\Entity\User;
use App\Enum\MindMap\Capacity;
use App\Repository\MindMap\ProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A player's progress on a step of a mind map path (docs/MIND_MAP.md, § 4): the passes of an
 * exercise, a boss beaten, a chest earned, an exercise skipped. Created at the first pass, skip or
 * chest; only changed under the run's lock or the player's path lock.
 */
#[ORM\Entity(repositoryClass: ProgressRepository::class)]
#[ORM\Table(name: 'mind_map_progress')]
#[ORM\UniqueConstraint(name: 'uniq_mind_map_progress_user_step', columns: ['user_id', 'capacity', 'step'])]
class Progress
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 16, enumType: Capacity::class)]
    private Capacity $capacity;

    /** The step's key in its path ({@see \App\MindMap\Path\Step::$key}). */
    #[ORM\Column(length: 24, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $step;

    /** Passes counted, up to the step's. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $passes = 0;

    /** All its passes done (boss beaten, chest earned): rewarded then, once. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    /** Skipped, and not completed since. */
    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $skippedAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(User $user, Capacity $capacity, string $step, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->capacity = $capacity;
        $this->step = $step;
        $this->updatedAt = $now;
    }

    /**
     * Counts a pass; the last one completes the step.
     *
     * @return bool whether this pass completed it
     */
    public function pass(int $required, \DateTimeImmutable $now): bool
    {
        if (null !== $this->completedAt) {
            return false;
        }
        $this->passes = min($required, $this->passes + 1);
        $this->updatedAt = $now;
        if ($this->passes < $required) {
            return false;
        }
        $this->completedAt = $now;
        $this->skippedAt = null;

        return true;
    }

    public function skip(\DateTimeImmutable $now): void
    {
        if (null === $this->completedAt && null === $this->skippedAt) {
            $this->skippedAt = $now;
            $this->updatedAt = $now;
        }
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getCapacity(): Capacity
    {
        return $this->capacity;
    }

    public function getStep(): string
    {
        return $this->step;
    }

    public function getPasses(): int
    {
        return $this->passes;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getSkippedAt(): ?\DateTimeImmutable
    {
        return $this->skippedAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
