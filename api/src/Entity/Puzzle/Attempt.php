<?php

declare(strict_types=1);

namespace App\Entity\Puzzle;

use App\Entity\User;
use App\Enum\Puzzle\AttemptStatus;
use App\Repository\Puzzle\AttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One try of a puzzle by a user. Created "pending" when the puzzle is handed out (server-side start
 * time), resolved once by the submission, whose outcome the server computes itself.
 *
 * `ratedPuzzleId` is a stored generated column (puzzle_id when rated, NULL otherwise) under a unique
 * index with user_id: the database itself forbids a second rated attempt on the same puzzle, and the
 * same index answers "has this user already played these puzzles?" during selection.
 */
#[ORM\Entity(repositoryClass: AttemptRepository::class)]
#[ORM\Table(name: 'puzzle_attempt')]
#[ORM\UniqueConstraint(name: 'uniq_puzzle_attempt_user_rated_puzzle', columns: ['user_id', 'rated_puzzle_id'])]
#[ORM\UniqueConstraint(name: 'uniq_puzzle_attempt_rating_change', columns: ['rating_change_id'])]
#[ORM\Index(name: 'idx_puzzle_attempt_user_started', columns: ['user_id', 'started_at'])]
#[ORM\Index(name: 'idx_puzzle_attempt_user_status', columns: ['user_id', 'status', 'started_at'])]
// Foreign-key indexes Doctrine would otherwise add under generated names.
#[ORM\Index(name: 'idx_puzzle_attempt_user', columns: ['user_id'])]
#[ORM\Index(name: 'idx_puzzle_attempt_puzzle', columns: ['puzzle_id'])]
class Attempt
{
    public const MAX_HINT_LEVEL = 2;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Puzzle::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Puzzle $puzzle;

    #[ORM\Column]
    private bool $rated;

    #[ORM\Column(
        options: ['unsigned' => true],
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: 'INT UNSIGNED GENERATED ALWAYS AS (IF(rated, puzzle_id, NULL)) STORED',
        generated: 'ALWAYS',
    )]
    private ?int $ratedPuzzleId = null;

    #[ORM\Column(length: 10, enumType: AttemptStatus::class)]
    private AttemptStatus $status = AttemptStatus::Pending;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    /** @var list<string>|null UCI moves tried by the player, wrong ones included */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $moves = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $mistakes = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $hintLevel = 0;

    #[ORM\Column(options: ['default' => false])]
    private bool $solutionShown = false;

    #[ORM\OneToOne(targetEntity: RatingChange::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?RatingChange $ratingChange = null;

    public function __construct(User $user, Puzzle $puzzle, bool $rated, \DateTimeImmutable $startedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->puzzle = $puzzle;
        $this->rated = $rated;
        $this->startedAt = $startedAt;
    }

    /**
     * @param list<string> $moves
     */
    public function resolve(
        bool $solved,
        array $moves,
        int $mistakes,
        int $hintLevel,
        bool $solutionShown,
        \DateTimeImmutable $submittedAt,
        ?RatingChange $ratingChange,
    ): void {
        if (AttemptStatus::Pending !== $this->status) {
            throw new \LogicException('Attempt already resolved.');
        }

        $this->status = $solved ? AttemptStatus::Solved : AttemptStatus::Failed;
        $this->moves = $moves;
        $this->mistakes = $mistakes;
        $this->hintLevel = $hintLevel;
        $this->solutionShown = $solutionShown;
        $this->submittedAt = $submittedAt;
        $this->durationMs = max(0, (int) round(((float) $submittedAt->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000));
        $this->ratingChange = $ratingChange;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getPuzzle(): Puzzle
    {
        return $this->puzzle;
    }

    public function isRated(): bool
    {
        return $this->rated;
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

    public function getRatingChange(): ?RatingChange
    {
        return $this->ratingChange;
    }
}
