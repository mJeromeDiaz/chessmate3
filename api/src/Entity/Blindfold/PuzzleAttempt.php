<?php

declare(strict_types=1);

namespace App\Entity\Blindfold;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Blindfold\AttemptStatus;
use App\Enum\Blindfold\PuzzleLevel;
use App\Repository\Blindfold\PuzzleAttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A puzzle played blindfold in a timed run (docs/BLINDFOLD.md): never rated, its own history. Its
 * time runs from the moment it is served (the memorization counts).
 */
#[ORM\Entity(repositoryClass: PuzzleAttemptRepository::class)]
#[ORM\Table(name: 'blindfold_puzzle_attempt')]
#[ORM\Index(name: 'idx_blindfold_puzzle_attempt_run', columns: ['run_id', 'started_at'])]
#[ORM\Index(name: 'idx_blindfold_puzzle_attempt_user_puzzle', columns: ['user_id', 'puzzle_id'])]
#[ORM\Index(name: 'idx_blindfold_puzzle_attempt_user_submitted', columns: ['user_id', 'submitted_at'])]
class PuzzleAttempt
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

    /** Id of the puzzle in the catalogue (no foreign key: another database, docs/DEPLOY_OVH.md, § 3). */
    #[ORM\Column(name: 'puzzle_id', options: ['unsigned' => true])]
    private int $puzzleId;

    #[ORM\Column(length: 8, enumType: PuzzleLevel::class)]
    private PuzzleLevel $level;

    /** Player moves asked (4: four or more). */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $length;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $visibleSeconds;

    #[ORM\Column(length: 8, enumType: AttemptStatus::class)]
    private AttemptStatus $status = AttemptStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    /** @var list<string>|null UCI moves tried, wrong ones included */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $moves = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $mistakes = 0;

    public function __construct(Run $run, int $puzzleId, PuzzleLevel $level, int $length, int $visibleSeconds, \DateTimeImmutable $startedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->puzzleId = $puzzleId;
        $this->level = $level;
        $this->length = $length;
        $this->visibleSeconds = $visibleSeconds;
        $this->startedAt = $startedAt;
    }

    /**
     * @param list<string> $moves
     */
    public function resolve(AttemptStatus $status, array $moves, int $mistakes, \DateTimeImmutable $at): void
    {
        if (AttemptStatus::Pending !== $this->status || AttemptStatus::Pending === $status) {
            throw new \LogicException('Attempt already resolved, or no verdict.');
        }
        $this->status = $status;
        $this->moves = $moves;
        $this->mistakes = $mistakes;
        $this->submittedAt = $at;
        $this->durationMs = max(0, (int) round(((float) $at->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000));
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

    public function getPuzzleId(): int
    {
        return $this->puzzleId;
    }

    public function getLevel(): PuzzleLevel
    {
        return $this->level;
    }

    public function getLength(): int
    {
        return $this->length;
    }

    public function getVisibleSeconds(): int
    {
        return $this->visibleSeconds;
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
}
