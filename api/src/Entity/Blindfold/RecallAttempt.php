<?php

declare(strict_types=1);

namespace App\Entity\Blindfold;

use App\Blindfold\Recall\Grade;
use App\Blindfold\Recall\RecallRules;
use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Blindfold\RecallStatus;
use App\Repository\Blindfold\RecallAttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A position to rebuild (docs/BLINDFOLD_RECALL.md): $fen is the position shown, $expectedPlacement
 * the one to rebuild (the same, or after the $announced moves), $puzzleId the puzzle it comes from
 * (null for a generated position). $durationMs runs from when it was served, memorising included.
 */
#[ORM\Entity(repositoryClass: RecallAttemptRepository::class)]
#[ORM\Table(name: 'blindfold_recall_attempt')]
#[ORM\Index(name: 'idx_blindfold_recall_attempt_run', columns: ['run_id', 'started_at'])]
#[ORM\Index(name: 'idx_blindfold_recall_attempt_user_level', columns: ['user_id', 'level', 'status'])]
#[ORM\Index(name: 'idx_blindfold_recall_attempt_user_puzzle', columns: ['user_id', 'puzzle_id'])]
class RecallAttempt
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

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $level;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $visibleSeconds;

    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $expectedPlacement;

    /** @var list<string> UCI moves announced once the position is hidden */
    #[ORM\Column(type: Types::JSON)]
    private array $announced;

    /** Id of the puzzle in the catalogue (no foreign key: another database, docs/DEPLOY_OVH.md, § 3). */
    #[ORM\Column(name: 'puzzle_id', nullable: true, options: ['unsigned' => true])]
    private ?int $puzzleId;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $pieceCount;

    #[ORM\Column(length: 8, enumType: RecallStatus::class)]
    private RecallStatus $status = RecallStatus::Pending;

    /** @var array<string, string>|null square => FEN piece letter, as placed */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $placement = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $correct = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $wrong = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $extra = 0;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $missing = 0;

    /** This rebuild opened the next level (the UNLOCK_EXACT-th exact one at its level). */
    #[ORM\Column(options: ['default' => false])]
    private bool $unlocked = false;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    /**
     * @param list<string> $announced
     * @param int|null     $visibleSeconds a mind map step's own time to memorize, null: the level's
     */
    public function __construct(Run $run, int $level, string $fen, string $expectedPlacement, array $announced, ?int $puzzleId, int $pieceCount, \DateTimeImmutable $startedAt, ?int $visibleSeconds = null)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->level = $level;
        $this->visibleSeconds = $visibleSeconds ?? RecallRules::level($level)['visibleSeconds'];
        $this->fen = $fen;
        $this->expectedPlacement = $expectedPlacement;
        $this->announced = $announced;
        $this->puzzleId = $puzzleId;
        $this->pieceCount = $pieceCount;
        $this->startedAt = $startedAt;
    }

    /**
     * @param array<string, string> $placement
     */
    public function resolve(Grade $grade, array $placement, \DateTimeImmutable $at): void
    {
        if (RecallStatus::Pending !== $this->status) {
            throw new \LogicException('Attempt already resolved.');
        }
        $this->status = $grade->status;
        $this->placement = $placement;
        $this->correct = $grade->correct;
        $this->wrong = $grade->wrong;
        $this->extra = $grade->extra;
        $this->missing = $grade->missing;
        $this->submittedAt = $at;
        $this->durationMs = max(0, (int) round(((float) $at->format('U.u') - (float) $this->startedAt->format('U.u')) * 1000));
    }

    public function markUnlocked(): void
    {
        $this->unlocked = true;
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

    public function getLevel(): int
    {
        return $this->level;
    }

    public function getVisibleSeconds(): int
    {
        return $this->visibleSeconds;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    public function getExpectedPlacement(): string
    {
        return $this->expectedPlacement;
    }

    /**
     * @return list<string>
     */
    public function getAnnounced(): array
    {
        return $this->announced;
    }

    public function getPuzzleId(): ?int
    {
        return $this->puzzleId;
    }

    public function getPieceCount(): int
    {
        return $this->pieceCount;
    }

    public function getStatus(): RecallStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return RecallStatus::Pending === $this->status;
    }

    /**
     * @return array<string, string>|null
     */
    public function getPlacement(): ?array
    {
        return $this->placement;
    }

    public function getCorrect(): int
    {
        return $this->correct;
    }

    public function getWrong(): int
    {
        return $this->wrong;
    }

    public function getExtra(): int
    {
        return $this->extra;
    }

    public function getMissing(): int
    {
        return $this->missing;
    }

    public function getAccuracy(): float
    {
        return RecallRules::accuracy($this->correct, $this->pieceCount, $this->extra);
    }

    public function isUnlocked(): bool
    {
        return $this->unlocked;
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
}
