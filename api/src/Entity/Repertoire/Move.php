<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Enum\Repertoire\MoveRole;
use App\Repository\Repertoire\MoveRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A move of a repertoire: an edge of its graph, from one position to another (docs/REPERTOIRE.md).
 *
 * Invariants enforced by the database, not only by the code:
 * - one move per (position, UCI): uniq_repertoire_move_from_uci;
 * - one reference move per position: generated reference_from_position_id + unique index;
 * - one canonical move into each position (the tree the lines and segments follow; the other
 *   moves into it are transpositions): generated canonical_to_position_id + unique index.
 * Generated columns are VIRTUAL: MySQL refuses STORED ones over ON DELETE CASCADE foreign keys.
 *
 * $canonical and $segment are derived (App\Repertoire\Graph\GraphIndexer), written in bulk.
 */
#[ORM\Entity(repositoryClass: MoveRepository::class)]
#[ORM\Table(name: 'repertoire_move')]
#[ORM\UniqueConstraint(name: 'uniq_repertoire_move_from_uci', columns: ['from_position_id', 'uci'])]
#[ORM\UniqueConstraint(name: 'uniq_repertoire_move_reference', columns: ['reference_from_position_id'])]
#[ORM\UniqueConstraint(name: 'uniq_repertoire_move_canonical', columns: ['canonical_to_position_id'])]
#[ORM\Index(name: 'idx_repertoire_move_repertoire', columns: ['repertoire_id'])]
#[ORM\Index(name: 'idx_repertoire_move_from', columns: ['from_position_id'])]
#[ORM\Index(name: 'idx_repertoire_move_to', columns: ['to_position_id'])]
#[ORM\Index(name: 'idx_repertoire_move_segment', columns: ['segment_id'])]
class Move
{
    public const COMMENT_MAX_LENGTH = 2000;
    public const MAX_NAGS = 4;

    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Repertoire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Repertoire $repertoire;

    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(name: 'from_position_id', nullable: false, onDelete: 'CASCADE')]
    private Position $from;

    #[ORM\ManyToOne(targetEntity: Position::class)]
    #[ORM\JoinColumn(name: 'to_position_id', nullable: false, onDelete: 'CASCADE')]
    private Position $to;

    #[ORM\Column(length: 5, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $uci;

    #[ORM\Column(length: 10, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $san;

    #[ORM\Column(length: 12, enumType: MoveRole::class)]
    private MoveRole $role;

    /** Display order among the moves from the same position (0 first: the main line). */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $sortOrder;

    /** Plain text, never HTML. */
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $comment = null;

    /** @var list<int> NAGs (1 "!", 2 "?", 3 "!!", 4 "??", 5 "!?", 6 "?!", ...) */
    #[ORM\Column(type: Types::JSON)]
    private array $nags = [];

    #[ORM\Column(options: ['default' => false])]
    private bool $canonical;

    #[ORM\ManyToOne(targetEntity: Segment::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Segment $segment = null;

    #[ORM\Column(
        type: 'uuid',
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: "BINARY(16) GENERATED ALWAYS AS (IF(role = 'reference', from_position_id, NULL)) VIRTUAL",
        generated: 'ALWAYS',
    )]
    private ?Uuid $referenceFromPositionId = null;

    #[ORM\Column(
        type: 'uuid',
        nullable: true,
        insertable: false,
        updatable: false,
        columnDefinition: 'BINARY(16) GENERATED ALWAYS AS (IF(canonical = 1, to_position_id, NULL)) VIRTUAL',
        generated: 'ALWAYS',
    )]
    private ?Uuid $canonicalToPositionId = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param Uuid|null $id given when a deleted move is restored (undo)
     */
    public function __construct(
        Position $from,
        Position $to,
        string $uci,
        string $san,
        MoveRole $role,
        int $sortOrder,
        bool $canonical,
        \DateTimeImmutable $now,
        ?Uuid $id = null,
    ) {
        $this->id = $id ?? Uuid::v7();
        $this->repertoire = $from->getRepertoire();
        $this->from = $from;
        $this->to = $to;
        $this->uci = $uci;
        $this->san = $san;
        $this->role = $role;
        $this->sortOrder = $sortOrder;
        $this->canonical = $canonical;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getRepertoire(): Repertoire
    {
        return $this->repertoire;
    }

    public function getFrom(): Position
    {
        return $this->from;
    }

    public function getTo(): Position
    {
        return $this->to;
    }

    public function getUci(): string
    {
        return $this->uci;
    }

    public function getSan(): string
    {
        return $this->san;
    }

    public function getRole(): MoveRole
    {
        return $this->role;
    }

    public function setRole(MoveRole $role): void
    {
        $this->role = $role;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): void
    {
        $this->sortOrder = $sortOrder;
    }

    public function getComment(): ?string
    {
        return $this->comment;
    }

    /**
     * @return list<int>
     */
    public function getNags(): array
    {
        return $this->nags;
    }

    /**
     * @param list<int> $nags
     */
    public function annotate(?string $comment, array $nags): void
    {
        $this->comment = $comment;
        $this->nags = $nags;
    }

    public function isCanonical(): bool
    {
        return $this->canonical;
    }

    public function getSegment(): ?Segment
    {
        return $this->segment;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
