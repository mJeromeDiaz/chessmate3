<?php

declare(strict_types=1);

namespace App\Entity\Notation;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Notation\AttemptStatus;
use App\Enum\Notation\Kind;
use App\Repository\Notation\AttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * An item of the notation module (docs/NOTATION.md): a position ($fen) drawn from a puzzle or from
 * a line of the player's repertoire ($shown: its moves before, $lineKey: its last segment), the
 * moves expected from it (UCI: the move announced, the sequence, the line to write) and what the
 * player answered (the moves played, or the half-moves written). Never rated.
 */
#[ORM\Entity(repositoryClass: AttemptRepository::class)]
#[ORM\Table(name: 'notation_attempt')]
#[ORM\Index(name: 'idx_notation_attempt_run', columns: ['run_id', 'started_at'])]
#[ORM\Index(name: 'idx_notation_attempt_user_puzzle', columns: ['user_id', 'puzzle_id'])]
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

    #[ORM\Column(length: 8, enumType: Kind::class)]
    private Kind $kind;

    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    /** @var list<string> UCI moves expected from $fen */
    #[ORM\Column(type: Types::JSON)]
    private array $expected;

    /** Id of the puzzle in the catalogue (no foreign key: another database, docs/DEPLOY_OVH.md, § 3). */
    #[ORM\Column(name: 'puzzle_id', nullable: true, options: ['unsigned' => true])]
    private ?int $puzzleId;

    /** @var list<string>|null a repertoire line: its moves played before $fen (UCI, from the initial position) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $shown = null;

    /** A repertoire line: its last segment (docs/REPERTOIRE.md, § 15), no foreign key (a line may change). */
    #[ORM\Column(length: 36, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $lineKey = null;

    /** A repertoire line: its opening's name. */
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $label = null;

    /** A repertoire line: the repertoire's colour (the board's orientation). */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $orientation = null;

    #[ORM\Column(length: 8, enumType: AttemptStatus::class)]
    private AttemptStatus $status = AttemptStatus::Pending;

    /** @var list<string>|null the moves played (UCI), or the half-moves written (SAN as understood) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $answer = null;

    /** The first wrong half-move (0-based), null when right. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $mistakeAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    /**
     * @param list<string> $expected
     */
    public function __construct(Run $run, Kind $kind, string $fen, array $expected, ?int $puzzleId, \DateTimeImmutable $startedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->kind = $kind;
        $this->fen = $fen;
        $this->expected = $expected;
        $this->puzzleId = $puzzleId;
        $this->startedAt = $startedAt;
    }

    /**
     * A repertoire line's own data.
     *
     * @param list<string> $shown
     */
    public function fromLine(array $shown, string $lineKey, ?string $label, ?string $orientation): void
    {
        $this->shown = $shown;
        $this->lineKey = $lineKey;
        $this->label = null === $label ? null : mb_substr($label, 0, 255);
        $this->orientation = $orientation;
    }

    /**
     * @param list<string> $answer
     */
    public function resolve(bool $right, array $answer, ?int $mistakeAt, \DateTimeImmutable $at): void
    {
        if (AttemptStatus::Pending !== $this->status) {
            throw new \LogicException('Attempt already resolved.');
        }
        $this->status = $right ? AttemptStatus::Right : AttemptStatus::Wrong;
        $this->answer = $answer;
        $this->mistakeAt = $right ? null : $mistakeAt;
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

    public function getKind(): Kind
    {
        return $this->kind;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    /**
     * @return list<string>
     */
    public function getExpected(): array
    {
        return $this->expected;
    }

    public function getPuzzleId(): ?int
    {
        return $this->puzzleId;
    }

    /**
     * @return list<string>
     */
    public function getShown(): array
    {
        return $this->shown ?? [];
    }

    public function getLineKey(): ?string
    {
        return $this->lineKey;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function getOrientation(): ?string
    {
        return $this->orientation;
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
    public function getAnswer(): ?array
    {
        return $this->answer;
    }

    public function getMistakeAt(): ?int
    {
        return $this->mistakeAt;
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
