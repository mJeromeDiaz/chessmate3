<?php

declare(strict_types=1);

namespace App\Entity\Woodpecker;

use App\Entity\Puzzle\Puzzle;
use App\Repository\Woodpecker\SetPuzzleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One puzzle of a set's frozen list. The puzzle foreign key has no cascade: a puzzle referenced by
 * a set can never be deleted, and a re-import must upsert (docs/PUZZLE_IMPORT.md, § 7).
 */
#[ORM\Entity(repositoryClass: SetPuzzleRepository::class, readOnly: true)]
#[ORM\Table(name: 'woodpecker_set_puzzle')]
#[ORM\UniqueConstraint(name: 'uniq_woodpecker_set_puzzle_set_puzzle', columns: ['set_id', 'puzzle_id'])]
#[ORM\Index(name: 'idx_woodpecker_set_puzzle_puzzle', columns: ['puzzle_id'])]
#[ORM\Index(name: 'idx_woodpecker_set_puzzle_set', columns: ['set_id'])]
class SetPuzzle
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Set::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private Set $set;

    /** 0-based position in the set's order. */
    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $position;

    #[ORM\ManyToOne(targetEntity: Puzzle::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Puzzle $puzzle;

    public function __construct(Set $set, int $position, Puzzle $puzzle)
    {
        $this->set = $set;
        $this->position = $position;
        $this->puzzle = $puzzle;
    }

    public function getSet(): Set
    {
        return $this->set;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getPuzzle(): Puzzle
    {
        return $this->puzzle;
    }
}
