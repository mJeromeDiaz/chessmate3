<?php

declare(strict_types=1);

namespace App\Entity\PuzzleRush;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\PuzzleRush\AttemptStatus;
use App\Repository\PuzzleRush\AttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A puzzle of a rush (docs/PUZZLE_RUSH.md): never rated, its own history. $position is the number
 * of puzzles solved in the run before it, $targetRating what the ramp aimed at. $durationMs is the
 * server's time since it was served, lowered to the client's think time when that is shorter.
 */
#[ORM\Entity(repositoryClass: AttemptRepository::class)]
#[ORM\Table(name: 'puzzle_rush_attempt')]
#[ORM\Index(name: 'idx_puzzle_rush_attempt_run', columns: ['run_id', 'started_at'])]
#[ORM\Index(name: 'idx_puzzle_rush_attempt_user_puzzle', columns: ['user_id', 'puzzle_id'])]
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

    public function __construct(Run $run, int $puzzleId, int $puzzleRating, int $position, int $targetRating, \DateTimeImmutable $startedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->puzzleId = $puzzleId;
        $this->puzzleRating = $puzzleRating;
        $this->position = $position;
        $this->targetRating = $targetRating;
        $this->startedAt = $startedAt;
    }

    /**
     * @param list<string> $moves
     * @param int|null     $thinkMs the client's think time: it may only lower the server's
     */
    public function resolve(AttemptStatus $status, array $moves, int $mistakes, \DateTimeImmutable $at, ?int $thinkMs = null): void
    {
        if (AttemptStatus::Pending !== $this->status || AttemptStatus::Pending === $status) {
            throw new \LogicException('Attempt already resolved, or no verdict.');
        }
        $elapsed = max(0, (int) round(((float) $at->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000));
        $this->status = $status;
        $this->moves = $moves;
        $this->mistakes = $mistakes;
        $this->submittedAt = $at;
        $this->durationMs = null === $thinkMs ? $elapsed : max(0, min($thinkMs, $elapsed));
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
