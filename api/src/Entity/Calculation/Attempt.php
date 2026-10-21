<?php

declare(strict_types=1);

namespace App\Entity\Calculation;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Calculation\AttemptStatus;
use App\Enum\Calculation\Ending;
use App\Repository\Calculation\AttemptRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A line of the calculation module (docs/CALCULATION.md): a puzzle's position ($fen, once the
 * opponent's first move is played), its solution (UCI, both sides), when the player asked to
 * answer ($readyAt, « Je réponds ») and the line they played. Never rated.
 */
#[ORM\Entity(repositoryClass: AttemptRepository::class)]
#[ORM\Table(name: 'calculation_attempt')]
#[ORM\Index(name: 'idx_calculation_attempt_run', columns: ['run_id', 'started_at'])]
#[ORM\Index(name: 'idx_calculation_attempt_user_puzzle', columns: ['user_id', 'puzzle_id'])]
class Attempt
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Run $run;

    #[ORM\Column(name: 'puzzle_id', options: ['unsigned' => true])]
    private int $puzzleId;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $puzzleRating;

    /** The line's length in the player's moves (1 to 4: a mate in 2 has 2). */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $moves;

    /** How the solution ends: a mate, or a gain played exactly. */
    #[ORM\Column(length: 8, enumType: Ending::class, options: ['default' => 'mate'])]
    private Ending $ending;

    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fen;

    /** A question on the position the line before reached (where, what), null for a line. */
    #[ORM\Column(length: 8, nullable: true)]
    private ?string $ask = null;

    /** The question's piece (a FEN letter) or square. */
    #[ORM\Column(length: 2, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $target = null;

    /** Its thinking time: 120 s, or the Métronome's tempo. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 120])]
    private int $thinkSeconds;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $expected;

    #[ORM\Column(length: 8, enumType: AttemptStatus::class)]
    private AttemptStatus $status = AttemptStatus::Pending;

    /** @var list<string>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $answer = null;

    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $mistakeAt = null;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $readyAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $submittedAt = null;

    #[ORM\Column(nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    /**
     * @param list<string> $expected
     */
    public function __construct(Run $run, int $puzzleId, int $puzzleRating, int $moves, Ending $ending, string $fen, array $expected, int $thinkSeconds, \DateTimeImmutable $startedAt)
    {
        $this->thinkSeconds = $thinkSeconds;
        $this->id = Uuid::v7();
        $this->user = $run->getUser();
        $this->run = $run;
        $this->puzzleId = $puzzleId;
        $this->puzzleRating = $puzzleRating;
        $this->moves = $moves;
        $this->ending = $ending;
        $this->fen = $fen;
        $this->expected = $expected;
        $this->startedAt = $startedAt;
    }

    /**
     * The question asked once $line is played (docs/CALCULATION.md, § 2 bis): $fen is the position
     * its solution reaches, $expected the answer (squares, or a FEN letter).
     *
     * @param list<string> $expected
     */
    public static function question(Attempt $line, string $ask, string $target, string $fen, array $expected, \DateTimeImmutable $startedAt): self
    {
        $question = new self($line->run, $line->puzzleId, $line->puzzleRating, $line->moves, $line->ending, $fen, $expected, $line->thinkSeconds, $startedAt);
        $question->ask = $ask;
        $question->target = $target;

        return $question;
    }

    /** « Je réponds »: the answer's clock starts. Once. */
    public function ready(\DateTimeImmutable $at): void
    {
        $this->readyAt ??= $at;
    }

    /**
     * @param list<string> $answer
     */
    public function resolve(AttemptStatus $status, array $answer, ?int $mistakeAt, \DateTimeImmutable $at): void
    {
        if (AttemptStatus::Pending !== $this->status) {
            throw new \LogicException('Attempt already resolved.');
        }
        if (AttemptStatus::Pending === $status) {
            throw new \LogicException('An attempt resolves to a verdict.');
        }
        $this->status = $status;
        $this->answer = $answer;
        $this->mistakeAt = AttemptStatus::Right === $status ? null : $mistakeAt;
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

    public function getPuzzleRating(): int
    {
        return $this->puzzleRating;
    }

    public function getMoves(): int
    {
        return $this->moves;
    }

    public function getEnding(): Ending
    {
        return $this->ending;
    }

    public function getFen(): string
    {
        return $this->fen;
    }

    public function isQuestion(): bool
    {
        return null !== $this->ask;
    }

    public function getAsk(): ?string
    {
        return $this->ask;
    }

    public function getTarget(): ?string
    {
        return $this->target;
    }

    public function getThinkSeconds(): int
    {
        return $this->thinkSeconds;
    }

    /**
     * @return list<string>
     */
    public function getExpected(): array
    {
        return $this->expected;
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

    public function getReadyAt(): ?\DateTimeImmutable
    {
        return $this->readyAt;
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
