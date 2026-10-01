<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Repository\Repertoire\SegmentRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A segment (tronçon) of a repertoire, the unit tested and tracked in statistics
 * (docs/REPERTOIRE.md): the chain of tested moves from the initial position (the trunk,
 * $startMoveId null) or from an opponent's move leaving a branching point, up to the next
 * branching point, the end of the line or a transposition. Every tested move belongs to exactly
 * one segment (repertoire_move.segment_id).
 *
 * Its identity is its first move: extended, it stays the same segment; cut in two by a new
 * branching point, the lower part is a new segment derived from it; when a branching point
 * disappears, the lower segment is archived, merged into the upper one. Segments are archived,
 * never deleted (their statistics stay), and reactivated when their first move comes back as a
 * start (undo). $startMoveId has no foreign key for that reason.
 *
 * Read-only for the ORM: rows are written by App\Repertoire\Graph\SegmentReconciler only.
 */
#[ORM\Entity(repositoryClass: SegmentRepository::class, readOnly: true)]
#[ORM\Table(name: 'repertoire_segment')]
#[ORM\UniqueConstraint(name: 'uniq_repertoire_segment_active_start', columns: ['active_start_move_id'])]
#[ORM\UniqueConstraint(name: 'uniq_repertoire_segment_active_trunk', columns: ['active_trunk_repertoire_id'])]
#[ORM\Index(name: 'idx_repertoire_segment_repertoire_archived', columns: ['repertoire_id', 'archived_at'])]
#[ORM\Index(name: 'idx_repertoire_segment_repertoire', columns: ['repertoire_id'])]
#[ORM\Index(name: 'idx_repertoire_segment_start', columns: ['start_move_id'])]
class Segment
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Repertoire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Repertoire $repertoire;

    /** The opponent's move that opens it (the deviation); null for the trunk. */
    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $startMoveId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $derivedFromSegmentId;

    #[ORM\Column(type: 'uuid', nullable: true)]
    private ?Uuid $mergedIntoSegmentId = null;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $moveCount = 0;

    /** Its reference moves: a segment without any is not presented in tests. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true, 'default' => 0])]
    private int $userMoveCount = 0;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $archivedAt = null;

    #[ORM\Column(
        type: 'uuid',
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: 'BINARY(16) GENERATED ALWAYS AS (IF(archived_at IS NULL, start_move_id, NULL)) VIRTUAL',
        generated: 'ALWAYS',
    )]
    private ?Uuid $activeStartMoveId = null;

    #[ORM\Column(
        type: 'uuid',
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: 'BINARY(16) GENERATED ALWAYS AS (IF(archived_at IS NULL AND start_move_id IS NULL, repertoire_id, NULL)) VIRTUAL',
        generated: 'ALWAYS',
    )]
    private ?Uuid $activeTrunkRepertoireId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRepertoire(): Repertoire
    {
        return $this->repertoire;
    }

    public function getStartMoveId(): ?Uuid
    {
        return $this->startMoveId;
    }

    public function isTrunk(): bool
    {
        return null === $this->startMoveId;
    }

    public function getDerivedFromSegmentId(): ?Uuid
    {
        return $this->derivedFromSegmentId;
    }

    public function getMergedIntoSegmentId(): ?Uuid
    {
        return $this->mergedIntoSegmentId;
    }

    public function getMoveCount(): int
    {
        return $this->moveCount;
    }

    public function getUserMoveCount(): int
    {
        return $this->userMoveCount;
    }

    public function isArchived(): bool
    {
        return null !== $this->archivedAt;
    }

    public function getArchivedAt(): ?\DateTimeImmutable
    {
        return $this->archivedAt;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
