<?php

declare(strict_types=1);

namespace App\Entity\Vision;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Vision\Ask;
use App\Enum\Vision\Kind;
use App\Enum\Vision\QuestionStatus;
use App\Repository\Vision\QuestionRepository;
use App\Vision\Drawing\Draw;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A question of the vision module (docs/VISION.md): a position shown ($fen) then hidden, the moves
 * announced after it (UCI), what is asked about the position they reach ($ask, $target, $side), what
 * answers it and what the player answered. Never rated.
 */
#[ORM\Entity(repositoryClass: QuestionRepository::class)]
#[ORM\Table(name: 'vision_question')]
#[ORM\Index(name: 'idx_vision_question_run', columns: ['run_id', 'started_at'])]
#[ORM\Index(name: 'idx_vision_question_user_puzzle', columns: ['user_id', 'puzzle_id'])]
class Question
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

    #[ORM\Column(length: 16, enumType: Kind::class)]
    private Kind $kind;

    #[ORM\Column(length: 12, enumType: Ask::class)]
    private Ask $ask;

    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    /** @var list<string> UCI moves announced once the position is hidden */
    #[ORM\Column(type: Types::JSON)]
    private array $announced;

    /** A piece letter (where), a square (what, attackers), null (en prise). */
    #[ORM\Column(length: 2, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $target;

    /** The side whose attackers are asked (attackers): white or black. */
    #[ORM\Column(length: 5, nullable: true)]
    private ?string $side;

    /** @var list<string> the squares that answer it, or the piece letter ('' for an empty square) */
    #[ORM\Column(type: Types::JSON)]
    private array $expected;

    /** Id of the puzzle in the catalogue (no foreign key: another database, docs/DEPLOY_OVH.md, § 3). */
    #[ORM\Column(name: 'puzzle_id', nullable: true, options: ['unsigned' => true])]
    private ?int $puzzleId;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $visibleSeconds;

    /** @var array<string, mixed>|null what its kind keeps besides ({@see Draw::$params}) */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $params;

    #[ORM\Column(length: 8, enumType: QuestionStatus::class)]
    private QuestionStatus $status = QuestionStatus::Pending;

    /** @var list<string>|null the squares clicked, or the piece chosen ('' for « empty ») */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $answer = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    public function __construct(Run $run, Kind $kind, Draw $draw, \DateTimeImmutable $startedAt)
    {
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->kind = $kind;
        $this->ask = $draw->ask;
        $this->fen = $draw->fen;
        $this->announced = $draw->announced;
        $this->target = $draw->target;
        $this->side = $draw->side;
        $this->expected = $draw->expected;
        $this->puzzleId = $draw->puzzleId;
        $this->visibleSeconds = $draw->visibleSeconds;
        $this->params = [] === $draw->params ? null : $draw->params;
        $this->startedAt = $startedAt;
    }

    /**
     * @param list<string> $answer
     */
    public function resolve(bool $right, array $answer, \DateTimeImmutable $at): void
    {
        if (QuestionStatus::Pending !== $this->status) {
            throw new \LogicException('Question already resolved.');
        }
        $this->status = $right ? QuestionStatus::Right : QuestionStatus::Wrong;
        $this->answer = $answer;
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

    public function getAsk(): Ask
    {
        return $this->ask;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    /**
     * @return list<string>
     */
    public function getAnnounced(): array
    {
        return $this->announced;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function getSide(): ?string
    {
        return $this->side;
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

    public function getVisibleSeconds(): int
    {
        return $this->visibleSeconds;
    }

    /**
     * @return array<string, mixed>
     */
    public function getParams(): array
    {
        return $this->params ?? [];
    }

    public function getStatus(): QuestionStatus
    {
        return $this->status;
    }

    public function isPending(): bool
    {
        return QuestionStatus::Pending === $this->status;
    }

    /**
     * @return list<string>|null
     */
    public function getAnswer(): ?array
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
