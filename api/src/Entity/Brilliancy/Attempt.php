<?php

declare(strict_types=1);

namespace App\Entity\Brilliancy;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Brilliancy\AttemptStatus;
use App\Enum\Brilliancy\Kind;
use App\Repository\Brilliancy\AttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A sacrifice shown in a timed run (docs/BRILLIANCY.md), then judged: the player's answer against
 * what the puzzle says. The puzzle is the catalogue's (its id only, docs/DEPLOY_OVH.md, § 3); the
 * position and the move shown are kept as served.
 */
#[ORM\Entity(repositoryClass: AttemptRepository::class)]
#[ORM\Table(name: 'brilliancy_attempt')]
#[ORM\Index(name: 'idx_brilliancy_attempt_run', columns: ['run_id', 'served_at'])]
#[ORM\Index(name: 'idx_brilliancy_attempt_user_puzzle', columns: ['user_id', 'puzzle_id'])]
#[ORM\Index(name: 'idx_brilliancy_attempt_user_submitted', columns: ['user_id', 'submitted_at'])]
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

    #[ORM\Column(options: ['unsigned' => true])]
    private int $puzzleId;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $puzzleRating;

    #[ORM\Column(length: 9, enumType: Kind::class)]
    private Kind $kind;

    /** The position shown, the move's side to move. */
    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    /** The move shown, UCI. */
    #[ORM\Column(length: 5, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $move;

    #[ORM\Column]
    private \DateTimeImmutable $servedAt;

    /** Seconds the run had left when it was served: the cap of its duration. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $seconds;

    #[ORM\Column(length: 8, enumType: AttemptStatus::class)]
    private AttemptStatus $status = AttemptStatus::Pending;

    #[ORM\Column(length: 9, nullable: true, enumType: Kind::class)]
    private ?Kind $answer = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    public function __construct(Run $run, int $puzzleId, int $puzzleRating, Kind $kind, string $fen, string $move, int $seconds, \DateTimeImmutable $servedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->puzzleId = $puzzleId;
        $this->puzzleRating = $puzzleRating;
        $this->kind = $kind;
        $this->fen = $fen;
        $this->move = $move;
        $this->seconds = $seconds;
        $this->servedAt = $servedAt;
    }

    public function resolve(Kind $answer, \DateTimeImmutable $at): void
    {
        if (AttemptStatus::Pending !== $this->status) {
            throw new \LogicException('Attempt already resolved.');
        }
        $this->answer = $answer;
        $this->status = $answer === $this->kind ? AttemptStatus::Right : AttemptStatus::Wrong;
        $this->submittedAt = $at;
        $elapsed = (int) round(((float) $at->format('U.u') - (float) $this->servedAt->format('U.u')) * 1000);
        $this->durationMs = max(0, min($this->seconds * 1000, $elapsed));
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

    public function getKind(): Kind
    {
        return $this->kind;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    public function getMove(): string
    {
        return $this->move;
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

    public function getAnswer(): ?Kind
    {
        return $this->answer;
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
