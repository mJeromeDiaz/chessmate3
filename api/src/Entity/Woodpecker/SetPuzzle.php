<?php

declare(strict_types=1);

namespace App\Entity\Woodpecker;

use App\Repository\Woodpecker\SetPuzzleRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * One puzzle of a set's frozen list, by its id in the puzzle catalogue (another database, so no
 * foreign key: a re-import must never delete a puzzle, docs/PUZZLE_IMPORT.md, § 7).
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

    #[ORM\Column(name: 'puzzle_id', options: ['unsigned' => true])]
    private int $puzzleId;

    public function __construct(Set $set, int $position, int $puzzleId)
    {
        $this->set = $set;
        $this->position = $position;
        $this->puzzleId = $puzzleId;
    }

    public function getSet(): Set
    {
        return $this->set;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getPuzzleId(): int
    {
        return $this->puzzleId;
    }
}
