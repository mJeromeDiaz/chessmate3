<?php

declare(strict_types=1);

namespace App\Entity\Woodpecker;

use App\Repository\Woodpecker\GrowthRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * One growth of a light set: puzzles appended because the round being played was running out of
 * unseen ones (docs/WOODPECKER.md). Never modified.
 */
#[ORM\Entity(repositoryClass: GrowthRepository::class, readOnly: true)]
#[ORM\Table(name: 'woodpecker_set_growth')]
#[ORM\Index(name: 'idx_woodpecker_set_growth_set_occurred', columns: ['set_id', 'occurred_at'])]
#[ORM\Index(name: 'idx_woodpecker_set_growth_set', columns: ['set_id'])]
class Growth
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: Set::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Set $set;

    /** Number of the round that ran out of puzzles. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $round;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $added;

    /** Size of the set after this growth. */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $puzzleCount;

    #[ORM\Column]
    private \DateTimeImmutable $occurredAt;

    public function __construct(Set $set, int $round, int $added, int $puzzleCount, \DateTimeImmutable $occurredAt)
    {
        $this->id = Uuid::v7();
        $this->set = $set;
        $this->round = $round;
        $this->added = $added;
        $this->puzzleCount = $puzzleCount;
        $this->occurredAt = $occurredAt;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getSet(): Set
    {
        return $this->set;
    }

    public function getRound(): int
    {
        return $this->round;
    }

    public function getAdded(): int
    {
        return $this->added;
    }

    public function getPuzzleCount(): int
    {
        return $this->puzzleCount;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
