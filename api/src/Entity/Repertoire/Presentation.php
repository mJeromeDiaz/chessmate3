<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Entity\Training\Run;
use App\Entity\User;
use App\Enum\Repertoire\PresentationStatus;
use App\Enum\Repertoire\TestUnit;
use App\Repository\Repertoire\PresentationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One presentation of a segment in a repertoire test (docs/REPERTOIRE.md), the unit of the
 * statistics. In line mode, a line gives one presentation per segment it crosses, sharing their
 * $unitId. Created when its unit starts, finished once, never changed afterwards; the segment's moves are kept as they
 * were ($moves), so that the history stays readable when the repertoire evolves.
 */
#[ORM\Entity(repositoryClass: PresentationRepository::class)]
#[ORM\Table(name: 'repertoire_presentation')]
#[ORM\Index(name: 'idx_repertoire_presentation_segment_finished', columns: ['segment_id', 'finished_at'])]
#[ORM\Index(name: 'idx_repertoire_presentation_repertoire_finished', columns: ['repertoire_id', 'finished_at'])]
#[ORM\Index(name: 'idx_repertoire_presentation_user_finished', columns: ['user_id', 'finished_at'])]
#[ORM\Index(name: 'idx_repertoire_presentation_run', columns: ['run_id'])]
class Presentation
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    #[ORM\ManyToOne(targetEntity: Repertoire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Repertoire $repertoire;

    /** Segments are archived, never deleted (except with their repertoire). */
    #[ORM\ManyToOne(targetEntity: Segment::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Segment $segment;

    #[ORM\ManyToOne(targetEntity: Run::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Run $run;

    /** The unit presented: the segment itself, or the line it is part of. */
    #[ORM\Column(type: 'uuid')]
    private Uuid $unitId;

    #[ORM\Column(length: 8, enumType: TestUnit::class)]
    private TestUnit $unit;

    /** 1 for the first presentation of the unit in the run, 2 and more when it comes back. */
    #[ORM\Column(name: 'presentation_rank', type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $rank;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $round;

    #[ORM\Column(length: 12, enumType: PresentationStatus::class)]
    private PresentationStatus $status = PresentationStatus::InProgress;

    /** Ply (from the initial position, on the canonical path) of the first wrong answer. */
    #[ORM\Column(type: Types::SMALLINT, nullable: true, options: ['unsigned' => true])]
    private ?int $firstErrorPly = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $positionsGraded = 0;

    /** @var list<string> SAN moves of the segment when presented */
    #[ORM\Column(type: Types::JSON)]
    private array $moves;

    /**
     * Normalized FEN of the position the segment starts from, when presented (what a replay of the
     * segment starts from); null for presentations recorded before it was kept.
     */
    #[ORM\Column(length: 92, nullable: true, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private ?string $startFen;

    /**
     * The segment's label when presented: its opening and its deviation ("3…c5"), null for the trunk.
     *
     * @var array{opening: array{eco: string, name: string}|null, move: string|null}
     */
    #[ORM\Column(type: Types::JSON)]
    private array $label;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $finishedAt = null;

    /** Server time, from the first move shown to the last answer. */
    #[ORM\Column(type: Types::INTEGER, nullable: true, options: ['unsigned' => true])]
    private ?int $durationMs = null;

    /**
     * @param list<string>                                                           $moves
     * @param array{opening: array{eco: string, name: string}|null, move: string|null} $label
     */
    public function __construct(User $user, Repertoire $repertoire, Segment $segment, ?Run $run, Uuid $unitId, TestUnit $unit, int $rank, int $round, array $moves, array $label, \DateTimeImmutable $startedAt, ?string $startFen = null)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->repertoire = $repertoire;
        $this->segment = $segment;
        $this->run = $run;
        $this->unitId = $unitId;
        $this->unit = $unit;
        $this->rank = $rank;
        $this->round = $round;
        $this->moves = $moves;
        $this->label = $label;
        $this->startedAt = $startedAt;
        $this->startFen = $startFen;
    }

    public function finish(PresentationStatus $status, ?int $firstErrorPly, int $positionsGraded, \DateTimeImmutable $finishedAt, int $durationMs): void
    {
        if (PresentationStatus::InProgress !== $this->status) {
            throw new \LogicException('Presentation already finished.');
        }
        $this->status = $status;
        $this->firstErrorPly = $firstErrorPly;
        $this->positionsGraded = $positionsGraded;
        $this->finishedAt = $finishedAt;
        $this->durationMs = $durationMs;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getRepertoire(): Repertoire
    {
        return $this->repertoire;
    }

    public function getSegment(): Segment
    {
        return $this->segment;
    }

    public function getRun(): ?Run
    {
        return $this->run;
    }

    public function getUnitId(): Uuid
    {
        return $this->unitId;
    }

    public function getUnit(): TestUnit
    {
        return $this->unit;
    }

    public function getRank(): int
    {
        return $this->rank;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function getStatus(): PresentationStatus
    {
        return $this->status;
    }

    public function getFirstErrorPly(): ?int
    {
        return $this->firstErrorPly;
    }

    public function getPositionsGraded(): int
    {
        return $this->positionsGraded;
    }

    /**
     * @return list<string>
     */
    public function getMoves(): array
    {
        return $this->moves;
    }

    /**
     * @return array{opening: array{eco: string, name: string}|null, move: string|null}
     */
    public function getLabel(): array
    {
        return $this->label;
    }

    public function getStartFen(): ?string
    {
        return $this->startFen;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function getDurationMs(): ?int
    {
        return $this->durationMs;
    }
}
