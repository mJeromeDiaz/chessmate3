<?php

declare(strict_types=1);

namespace App\Entity\Endgame;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Endgame\AttemptStatus;
use App\Repository\Endgame\AttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A position of the endgames module served in a timed run (docs/ENDGAMES.md, § 5), then judged
 * from the whole game played. The position comes from the lesson (textbook) or the endgame
 * database's pool (`positionId`, no foreign key: another database).
 */
#[ORM\Entity(repositoryClass: AttemptRepository::class)]
#[ORM\Table(name: 'endgame_attempt')]
#[ORM\Index(name: 'idx_endgame_attempt_run', columns: ['run_id', 'served_at'])]
#[ORM\Index(name: 'idx_endgame_attempt_user_submitted', columns: ['user_id', 'submitted_at'])]
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

    /** {@see \App\Endgame\Lesson\Catalogue} */
    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $lessonKey;

    /** The position served, the player to move. */
    #[ORM\Column(length: 100, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    /** endgame_position.id, null for the textbook position. */
    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $positionId;

    #[ORM\Column]
    private bool $textbook;

    /** Maia's Elo, null for the tablebase's best defence (« Parfait »). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $opponentElo;

    /** Moves allowed to win (null for a draw to hold). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $budgetMoves;

    /** Moves to keep a draw (null for a win). */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $holdMoves;

    #[ORM\Column]
    private \DateTimeImmutable $servedAt;

    #[ORM\Column(length: 10, enumType: AttemptStatus::class)]
    private AttemptStatus $status = AttemptStatus::Pending;

    /** @var list<string>|null the whole game, both sides, UCI */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $moves = null;

    /** Ply (0-based in `moves`) of the player's move that spoiled the result. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $mistakeAt = null;

    /** A move that kept the result there (UCI). */
    #[ORM\Column(length: 5, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $bestMove = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $playerMoves = null;

    /** Judged by the browser's engine: the tablebase was unavailable. */
    #[ORM\Column]
    private bool $engineJudged = false;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    public function __construct(Run $run, string $lessonKey, string $fen, ?int $positionId, bool $textbook, ?int $opponentElo, ?int $budgetMoves, ?int $holdMoves, \DateTimeImmutable $servedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->lessonKey = $lessonKey;
        $this->fen = $fen;
        $this->positionId = $positionId;
        $this->textbook = $textbook;
        $this->opponentElo = $opponentElo;
        $this->budgetMoves = $budgetMoves;
        $this->holdMoves = $holdMoves;
        $this->servedAt = $servedAt;
    }

    /**
     * @param list<string> $moves
     */
    public function resolve(AttemptStatus $status, array $moves, ?int $mistakeAt, ?string $bestMove, int $playerMoves, bool $engineJudged, \DateTimeImmutable $at): void
    {
        $this->status = $status;
        $this->moves = $moves;
        $this->mistakeAt = $mistakeAt;
        $this->bestMove = $bestMove;
        $this->playerMoves = $playerMoves;
        $this->engineJudged = $engineJudged;
        $this->submittedAt = $at;
        $this->durationMs = max(0, (int) round(((float) $at->format('U.u') - (float) $this->servedAt->format('U.u')) * 1000));
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

    public function getLessonKey(): string
    {
        return $this->lessonKey;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    public function getPositionId(): ?int
    {
        return $this->positionId;
    }

    public function isTextbook(): bool
    {
        return $this->textbook;
    }

    public function getOpponentElo(): ?int
    {
        return $this->opponentElo;
    }

    public function getBudgetMoves(): ?int
    {
        return $this->budgetMoves;
    }

    public function getHoldMoves(): ?int
    {
        return $this->holdMoves;
    }

    public function getServedAt(): \DateTimeImmutable
    {
        return $this->servedAt;
    }

    public function getStatus(): AttemptStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return AttemptStatus::Pending === $this->status;
    }

    /**
     * @return list<string>|null
     */
    public function getMoves(): ?array
    {
        return $this->moves;
    }

    public function getMistakeAt(): ?int
    {
        return $this->mistakeAt;
    }

    public function getBestMove(): ?string
    {
        return $this->bestMove;
    }

    public function getPlayerMoves(): ?int
    {
        return $this->playerMoves;
    }

    public function isEngineJudged(): bool
    {
        return $this->engineJudged;
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
