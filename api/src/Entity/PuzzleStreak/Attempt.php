<?php

declare(strict_types=1);

namespace App\Entity\PuzzleStreak;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\PuzzleStreak\AttemptStatus;
use App\Repository\PuzzleStreak\AttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A puzzle of a streak (docs/PUZZLE_STREAK.md): never rated, its own history. $position is its
 * place in the streak (0-based: the puzzles solved before it), $targetRating what the ramp aimed at.
 */
#[ORM\Entity(repositoryClass: AttemptRepository::class)]
#[ORM\Table(name: 'puzzle_streak_attempt')]
#[ORM\Index(name: 'idx_puzzle_streak_attempt_run', columns: ['run_id', 'started_at'])]
#[ORM\Index(name: 'idx_puzzle_streak_attempt_user_puzzle', columns: ['user_id', 'puzzle_id'])]
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

    #[ORM\ManyToOne(targetEntity: Streak::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Streak $streak;

    /** Id of the puzzle in the catalogue (no foreign key: another database, docs/DEPLOY_OVH.md, § 3). */
    #[ORM\Column(name: 'puzzle_id', options: ['unsigned' => true])]
    private int $puzzleId;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $puzzleRating;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $position;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $targetRating;

    #[ORM\Column(length: 8, enumType: AttemptStatus::class)]
    private AttemptStatus $status = AttemptStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    /** @var list<string>|null UCI moves tried, the wrong one included */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $moves = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $mistakes = 0;

    public function __construct(Streak $streak, int $puzzleId, int $puzzleRating, int $targetRating, \DateTimeImmutable $startedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $streak->getUser();
        $this->run = $streak->getRun();
        $this->streak = $streak;
        $this->puzzleId = $puzzleId;
        $this->puzzleRating = $puzzleRating;
        $this->position = $streak->getLength();
        $this->targetRating = $targetRating;
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

    public function getStreak(): Streak
    {
        return $this->streak;
    }

    public function getPuzzleId(): int
    {
        return $this->puzzleId;
    }

    public function getPuzzleRating(): int
    {
        return $this->puzzleRating;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getTargetRating(): int
    {
        return $this->targetRating;
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
