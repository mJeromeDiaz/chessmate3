<?php

declare(strict_types=1);

namespace App\Entity\Puzzle;

use App\Enum\Puzzle\ThemeCategory;
use App\Repository\Puzzle\ThemeRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Reference list of the Lichess puzzle themes (see {@see \App\Puzzle\Theme\ThemeCatalog}).
 *
 * `puzzleCount` is precomputed by `app:puzzle:rebuild-selection` (selectable puzzles only), so the
 * theme page never runs a COUNT over millions of rows.
 */
#[ORM\Entity(repositoryClass: ThemeRepository::class)]
#[ORM\Table(name: 'puzzle_theme')]
#[ORM\UniqueConstraint(name: 'uniq_puzzle_theme_key', columns: ['theme_key'])]
class Theme
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private ?int $id = null;

    /** Lichess key, as found in the CSV (e.g. "mateIn2"). "key" is reserved in MySQL, hence the column name. */
    #[ORM\Column(name: 'theme_key', length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $key;

    #[ORM\Column(length: 20, enumType: ThemeCategory::class)]
    private ThemeCategory $category;

    #[ORM\Column(length: 64)]
    private string $labelEn;

    #[ORM\Column(length: 64)]
    private string $labelFr;

    #[ORM\Column(length: 255)]
    private string $descriptionEn;

    #[ORM\Column(length: 255)]
    private string $descriptionFr;

    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $position;

    #[ORM\Column(options: ['unsigned' => true, 'default' => 0])]
    private int $puzzleCount = 0;

    public function __construct(string $key)
    {
        $this->key = $key;
    }

    public function update(ThemeCategory $category, string $labelEn, string $labelFr, string $descriptionEn, string $descriptionFr, int $position): void
    {
        $this->category = $category;
        $this->labelEn = $labelEn;
        $this->labelFr = $labelFr;
        $this->descriptionEn = $descriptionEn;
        $this->descriptionFr = $descriptionFr;
        $this->position = $position;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getCategory(): ThemeCategory
    {
        return $this->category;
    }

    public function getLabelEn(): string
    {
        return $this->labelEn;
    }

    public function getLabelFr(): string
    {
        return $this->labelFr;
    }

    public function getDescriptionEn(): string
    {
        return $this->descriptionEn;
    }

    public function getDescriptionFr(): string
    {
        return $this->descriptionFr;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function getPuzzleCount(): int
    {
        return $this->puzzleCount;
    }
}
