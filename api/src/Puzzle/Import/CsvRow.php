<?php

declare(strict_types=1);

namespace App\Puzzle\Import;

use App\Puzzle\Selection\Quality;

/**
 * One validated line of a Lichess puzzle export (docs/PUZZLE_IMPORT.md, § 1), typed as the
 * `puzzle` table stores it.
 */
final readonly class CsvRow
{
    /**
     * @param list<string>      $themes
     * @param list<string>|null $openingTags
     */
    public function __construct(
        public string $lichessId,
        public string $fen,
        public string $moves,
        public int $rating,
        public int $ratingDeviation,
        public int $popularity,
        public int $nbPlays,
        public array $themes,
        public string $gameUrl,
        public ?array $openingTags,
        public ?string $dailyDate,
    ) {
    }

    public function isSelectable(): bool
    {
        return Quality::isSelectable($this->popularity, $this->nbPlays);
    }
}
