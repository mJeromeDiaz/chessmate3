<?php

declare(strict_types=1);

namespace App\Entity\Catalog;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Index of « Brillant ou Blunder » (docs/BRILLIANCY.md, § 2): "puzzle P shows a sacrifice at its
 * move `ply`" (0: the opponent's blunder, 1: the player's brilliant move), selectable puzzles only.
 *
 * Derived data, rebuilt from `puzzle` by {@see \App\Brilliancy\Index\Indexer}. The clustered primary
 * key (ply, rating, random_key, puzzle_id) makes "a random blunder around rating R" one contiguous
 * index range, as for {@see ThemeMembership}; the puzzle_id index serves the rebuild's deletes.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'brilliancy_position')]
#[ORM\Index(name: 'idx_brilliancy_position_puzzle', columns: ['puzzle_id'])]
class BrilliancyPosition
{
    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $ply;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $rating;

    #[ORM\Id]
    #[ORM\Column(options: ['unsigned' => true])]
    private int $randomKey;

    #[ORM\Id]
    #[ORM\Column(options: ['unsigned' => true])]
    private int $puzzleId;

    public function __construct(int $ply, int $rating, int $randomKey, int $puzzleId)
    {
        $this->ply = $ply;
        $this->rating = $rating;
        $this->randomKey = $randomKey;
        $this->puzzleId = $puzzleId;
    }

    public function getPly(): int
    {
        return $this->ply;
    }

    public function getRating(): int
    {
        return $this->rating;
    }

    public function getRandomKey(): int
    {
        return $this->randomKey;
    }

    public function getPuzzleId(): int
    {
        return $this->puzzleId;
    }
}
