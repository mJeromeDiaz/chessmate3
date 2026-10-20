<?php

declare(strict_types=1);

namespace App\Entity\MindMap;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\MindMap\BossItemStatus;
use App\Enum\MindMap\BossQuestion;
use App\Repository\MindMap\BossItemRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A question of a boss fight (docs/MIND_MAP.md, § 8): a square ($square) or a puzzle's position
 * ($fen), what it expects (a square, `light` or `dark`, a move in UCI) and what the player answered
 * (a square, a colour, a move played in UCI, a move written in SAN), within its time or late.
 */
#[ORM\Entity(repositoryClass: BossItemRepository::class)]
#[ORM\Table(name: 'mind_map_boss_item')]
#[ORM\Index(name: 'idx_mind_map_boss_item_run', columns: ['run_id', 'started_at'])]
class BossItem
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

    /** The boss's character (`jongleur`). */
    #[ORM\Column(length: 16)]
    private string $boss;

    #[ORM\Column(length: 8, enumType: BossQuestion::class)]
    private BossQuestion $question;

    #[ORM\Column(length: 2, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $square;

    #[ORM\Column(length: 92, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $fen;

    /** A square, `light` or `dark`, a move (UCI). */
    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $expected;

    #[ORM\Column(length: 8, enumType: BossItemStatus::class)]
    private BossItemStatus $status = BossItemStatus::Pending;

    /** As answered (a square, a colour, a move played in UCI, a move written in SAN), null when nothing was. */
    #[ORM\Column(length: 32, nullable: true, options: ['collation' => 'utf8mb4_bin'])]
    private ?string $answer = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    public function __construct(Run $run, string $boss, BossQuestion $question, ?string $square, ?string $fen, string $expected, \DateTimeImmutable $startedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->boss = $boss;
        $this->question = $question;
        $this->square = $square;
        $this->fen = $fen;
        $this->expected = $expected;
        $this->startedAt = $startedAt;
    }

    public function resolve(bool $right, bool $late, ?string $answer, \DateTimeImmutable $at): void
    {
        if (BossItemStatus::Pending !== $this->status) {
            throw new \LogicException('Boss item already resolved.');
        }
        $this->status = match (true) {
            $late => BossItemStatus::Late,
            $right => BossItemStatus::Right,
            default => BossItemStatus::Wrong,
        };
        $this->answer = null === $answer ? null : mb_substr($answer, 0, 32);
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

    public function getBoss(): string
    {
        return $this->boss;
    }

    public function getQuestion(): BossQuestion
    {
        return $this->question;
    }

    public function getSquare(): ?string
    {
        return $this->square;
    }

    public function getFen(): ?string
    {
        return $this->fen;
    }

    public function getExpected(): string
    {
        return $this->expected;
    }

    public function getStatus(): BossItemStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return BossItemStatus::Pending === $this->status;
    }

    public function isRight(): bool
    {
        return BossItemStatus::Right === $this->status;
    }

    public function getAnswer(): ?string
    {
        return $this->answer;
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
