<?php

declare(strict_types=1);

namespace App\Entity\Woodpecker;

use App\Entity\User;
use App\Enum\Woodpecker\SetStatus;
use App\Repository\Woodpecker\SetRepository;
use App\Woodpecker\Set\SetConfig;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A Woodpecker set: a frozen, ordered list of puzzles ({@see SetPuzzle}) solved in successive
 * cycles ({@see Cycle}), each faster than the previous one (docs/WOODPECKER.md).
 *
 * `activeUserId` is a generated column (user_id while active or paused, NULL otherwise) under a
 * unique index: the database itself enforces one ongoing set per user. VIRTUAL, not STORED: MySQL
 * refuses an ON DELETE CASCADE foreign key on the base column of a stored generated column.
 */
#[ORM\Entity(repositoryClass: SetRepository::class)]
#[ORM\Table(name: 'woodpecker_set')]
#[ORM\UniqueConstraint(name: 'uniq_woodpecker_set_active_user', columns: ['active_user_id'])]
#[ORM\Index(name: 'idx_woodpecker_set_user_created', columns: ['user_id', 'created_at'])]
#[ORM\Index(name: 'idx_woodpecker_set_user', columns: ['user_id'])]
class Set
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\Column(length: 80)]
    private string $name;

    #[ORM\Column(length: 16, enumType: SetStatus::class)]
    private SetStatus $status = SetStatus::Active;

    #[ORM\Column(
        type: 'uuid',
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: "BINARY(16) GENERATED ALWAYS AS (IF(status IN ('active', 'paused'), user_id, NULL)) VIRTUAL",
        generated: 'ALWAYS',
    )]
    private ?Uuid $activeUserId = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $puzzleCount;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $ratingMin;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $ratingMax;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $themes;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $cycleCount;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $firstCycleDays;

    #[ORM\Column]
    private float $reductionFactor;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $minCycleDays;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $restDays;

    #[ORM\Column]
    private bool $shuffle;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $pausedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $completedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $abandonedAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    public function __construct(User $user, string $name, SetConfig $config, \DateTimeImmutable $createdAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->name = $name;
        $this->puzzleCount = $config->puzzleCount;
        $this->ratingMin = $config->ratingMin;
        $this->ratingMax = $config->ratingMax;
        $this->themes = $config->themes;
        $this->cycleCount = $config->cycleCount;
        $this->firstCycleDays = $config->firstCycleDays;
        $this->reductionFactor = $config->reductionFactor;
        $this->minCycleDays = $config->minCycleDays;
        $this->restDays = $config->restDays;
        $this->shuffle = $config->shuffle;
        $this->createdAt = $createdAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getStatus(): SetStatus
    {
        return $this->status;
    }

    public function getActiveUserId(): ?Uuid
    {
        return $this->activeUserId;
    }

    public function getConfig(): SetConfig
    {
        return new SetConfig(
            $this->puzzleCount,
            $this->ratingMin,
            $this->ratingMax,
            $this->themes,
            $this->cycleCount,
            $this->firstCycleDays,
            $this->reductionFactor,
            $this->minCycleDays,
            $this->restDays,
            $this->shuffle,
        );
    }

    public function getPuzzleCount(): int
    {
        return $this->puzzleCount;
    }

    public function getCycleCount(): int
    {
        return $this->cycleCount;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getPausedAt(): ?\DateTimeImmutable
    {
        return $this->pausedAt;
    }

    public function getCompletedAt(): ?\DateTimeImmutable
    {
        return $this->completedAt;
    }

    public function getAbandonedAt(): ?\DateTimeImmutable
    {
        return $this->abandonedAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    public function pause(\DateTimeImmutable $at): void
    {
        $this->assertStatus(SetStatus::Active);
        $this->status = SetStatus::Paused;
        $this->pausedAt = $at;
    }

    /**
     * @return \DateTimeImmutable when the pause began (to shift the deadlines by its length)
     */
    public function resume(): \DateTimeImmutable
    {
        $this->assertStatus(SetStatus::Paused);
        $pausedAt = $this->pausedAt ?? throw new \LogicException('Paused set without pause date.');
        $this->status = SetStatus::Active;
        $this->pausedAt = null;

        return $pausedAt;
    }

    public function complete(\DateTimeImmutable $at): void
    {
        $this->assertStatus(SetStatus::Active);
        $this->status = SetStatus::Completed;
        $this->completedAt = $at;
    }

    public function abandon(\DateTimeImmutable $at): void
    {
        if (!$this->status->isOngoing()) {
            throw new \DomainException('Only an ongoing set can be abandoned.');
        }
        $this->status = SetStatus::Abandoned;
        $this->pausedAt = null;
        $this->abandonedAt = $at;
    }

    public function archive(\DateTimeImmutable $at): void
    {
        if ($this->status->isOngoing()) {
            throw new \DomainException('Only a completed or abandoned set can be archived.');
        }
        $this->archivedAt ??= $at;
    }

    private function assertStatus(SetStatus $expected): void
    {
        if ($this->status !== $expected) {
            throw new \DomainException(sprintf('The set is %s, not %s.', $this->status->value, $expected->value));
        }
    }
}
