<?php

declare(strict_types=1);

namespace App\Entity\Repertoire;

use App\Repository\Repertoire\RevisionRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * The undo journal of a repertoire (App\Repertoire\Graph\GraphEditor): one row per change, with
 * what undoes it ($inverse). A stack: undo applies and removes the latest row; only the last
 * {@see \App\Repertoire\Limits::UNDO_DEPTH} are kept.
 */
#[ORM\Entity(repositoryClass: RevisionRepository::class)]
#[ORM\Table(name: 'repertoire_revision')]
#[ORM\Index(name: 'idx_repertoire_revision_repertoire_version', columns: ['repertoire_id', 'version'])]
#[ORM\Index(name: 'idx_repertoire_revision_repertoire', columns: ['repertoire_id'])]
class Revision
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Repertoire::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Repertoire $repertoire;

    /** The repertoire version this change produced. */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $version;

    /** What was done: add, delete, reference, promote, annotate. */
    #[ORM\Column(length: 16)]
    private string $operation;

    /** @var array<string, mixed> */
    #[ORM\Column(type: Types::JSON)]
    private array $inverse;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    /**
     * @param array<string, mixed> $inverse
     */
    public function __construct(Repertoire $repertoire, int $version, string $operation, array $inverse, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->repertoire = $repertoire;
        $this->version = $version;
        $this->operation = $operation;
        $this->inverse = $inverse;
        $this->createdAt = $now;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getVersion(): int
    {
        return $this->version;
    }

    public function getOperation(): string
    {
        return $this->operation;
    }

    /**
     * @return array<string, mixed>
     */
    public function getInverse(): array
    {
        return $this->inverse;
    }
}
