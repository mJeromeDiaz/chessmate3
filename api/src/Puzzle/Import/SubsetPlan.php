<?php

declare(strict_types=1);

namespace App\Puzzle\Import;

/**
 * Which puzzles of the export the import keeps, as computed by {@see SubsetPlanner}, with the
 * figures the command reports.
 *
 * At the threshold score of a band, the share kept is drawn from a hash of the Lichess id rather
 * than at random: running the import again over the same export keeps the same puzzles.
 */
final readonly class SubsetPlan
{
    /**
     * @param array<int, array{int, float}> $thresholds    lowest score kept and the share kept at it, by band
     * @param array<string, true>           $protected     rare theme keys, kept whole
     * @param array<int, int>               $available     selectable puzzles by band
     * @param array<int, int>               $quotas        puzzles kept by band, rare themes aside
     * @param array<string, int>            $rareThemes    selectable puzzles of each rare theme
     * @param array<string, int>            $unknownThemes puzzles of each theme key unknown to the app
     */
    public function __construct(
        private array $thresholds,
        private array $protected,
        public array $available,
        public array $quotas,
        public array $rareThemes,
        public array $unknownThemes,
        public int $total,
        public int $selectable,
        public float $themesPerPuzzle,
    ) {
    }

    public function accepts(CsvRow $row): bool
    {
        if (!$row->isSelectable()) {
            return false;
        }
        foreach ($row->themes as $theme) {
            if (isset($this->protected[$theme])) {
                return true;
            }
        }

        $threshold = $this->thresholds[SubsetPlanner::band($row)] ?? null;
        if (null === $threshold) {
            return false;
        }
        [$minScore, $share] = $threshold;
        $score = SubsetPlanner::score($row);

        return $score > $minScore || ($score === $minScore && crc32($row->lichessId) / 4294967296 < $share);
    }

    /**
     * Upper bound of the puzzles kept: the quotas plus every puzzle of a rare theme (some of them
     * are already within the quotas).
     */
    public function maxKept(): int
    {
        return min($this->selectable, array_sum($this->quotas) + array_sum($this->rareThemes));
    }
}
