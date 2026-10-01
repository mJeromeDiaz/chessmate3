<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Enum\Repertoire\TrashReason;
use App\Repository\Repertoire\TrashedSuiteRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A suite set aside (docs/REPERTOIRE.md, "Corbeille"): a move and everything only reachable
 * through it, as rows with their ids, so that restoring it brings back its segments and cards
 * with their progress. Written in SQL by App\Repertoire\Graph\GraphEditor: read-only here.
 */
#[ORM\Entity(repositoryClass: TrashedSuiteRepository::class, readOnly: true)]
#[ORM\Table(name: 'repertoire_trash')]
#[ORM\Index(name: 'idx_repertoire_trash_repertoire_created', columns: ['repertoire_id', 'created_at'])]
class TrashedSuite
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Repertoire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Repertoire $repertoire;

    #[ORM\Column(length: 16, enumType: TrashReason::class)]
    private TrashReason $reason;

    /** The position the suite starts from (normalized FEN): it stays in the repertoire. */
    #[ORM\Column(length: 92, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $fromFen;

    /** The suite's first move. */
    #[ORM\Column(length: 5, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $uci;

    #[ORM\Column(length: 10, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $san;

    /** @var list<string> SAN moves from the initial position to the start, when set aside */
    #[ORM\Column(type: Types::JSON)]
    private array $path;

    /** Positions set aside (the start excluded). */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $positionCount;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $moveCount;

    /**
     * The rows: {positions: [...], moves: [...]}, as App\Repertoire\Graph\GraphEditor reads them.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'suite_rows', type: Types::JSON)]
    private array $rows;

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

    public function getReason(): TrashReason
    {
        return $this->reason;
    }

    public function getFromFen(): string
    {
        return $this->fromFen;
    }

    public function getUci(): string
    {
        return $this->uci;
    }

    public function getSan(): string
    {
        return $this->san;
    }

    /**
     * @return list<string>
     */
    public function getPath(): array
    {
        return $this->path;
    }

    public function getPositionCount(): int
    {
        return $this->positionCount;
    }

    public function getMoveCount(): int
    {
        return $this->moveCount;
    }

    /**
     * @return array<string, mixed>
     */
    public function getRows(): array
    {
        return $this->rows;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
