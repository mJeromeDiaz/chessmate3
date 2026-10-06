<?php

declare(strict_types=1);

namespace App\Entity\Catalog;

use App\Repository\Catalog\ThemeMembershipRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Selection index "puzzle P has theme T", for selectable puzzles only (~20M rows).
 *
 * Derived data, rebuilt from `puzzle.themes` by {@see \App\Puzzle\Selection\SelectionRebuilder}.
 * The clustered primary key (theme_id, rating, random_key, puzzle_id) makes "a random puzzle of
 * theme T around rating R" one contiguous index range, whatever the theme's frequency. No foreign
 * keys on purpose: they would cost an extra 20M-row index on puzzle_id and slow the bulk rebuild.
 */
#[ORM\Entity(repositoryClass: ThemeMembershipRepository::class, readOnly: true)]
#[ORM\Table(name: 'puzzle_theme_membership')]
class ThemeMembership
{
    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $themeId;

    #[ORM\Id]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $rating;

    #[ORM\Id]
    #[ORM\Column(options: ['unsigned' => true])]
    private int $randomKey;

    #[ORM\Id]
    #[ORM\Column(options: ['unsigned' => true])]
    private int $puzzleId;

    public function __construct(int $themeId, int $rating, int $randomKey, int $puzzleId)
    {
        $this->themeId = $themeId;
        $this->rating = $rating;
        $this->randomKey = $randomKey;
        $this->puzzleId = $puzzleId;
    }

    public function getThemeId(): int
    {
        return $this->themeId;
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
