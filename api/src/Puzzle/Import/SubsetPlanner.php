<?php

declare(strict_types=1);

namespace App\Puzzle\Import;

use App\Puzzle\Theme\ThemeCatalog;

/**
 * First pass of the import: counts the selectable puzzles of the export, by rating band and
 * quality score, and by theme (a few KB, whatever the export size), then derives the
 * {@see SubsetPlan} that keeps about `target` of them (docs/PUZZLE_IMPORT.md).
 *
 * The subset is balanced: each 100-point rating band keeps its share of the selectable puzzles,
 * the best ones first (popularity, then play count), and every puzzle of a rare theme is kept, so
 * neither strong players nor rare themes run dry.
 */
final class SubsetPlanner
{
    public const BAND_WIDTH = 100;

    /** @var array<int, array<int, int>> selectable puzzles by band, then score */
    private array $histogram = [];

    /** @var array<string, int> selectable puzzles by known theme key */
    private array $themeCounts = [];

    /** @var array<string, int> puzzles by theme key unknown to {@see ThemeCatalog} */
    private array $unknownThemes = [];

    /** @var array<string, true> */
    private readonly array $knownThemes;

    private int $total = 0;
    private int $selectable = 0;
    private int $themeLinks = 0;

    public function __construct()
    {
        $this->knownThemes = array_fill_keys(array_column(ThemeCatalog::THEMES, 0), true);
    }

    /**
     * Quality score: popularity first, the play count (log scale) as a tie-break.
     */
    public static function score(CsvRow $row): int
    {
        return $row->popularity * 32 + min(31, (int) floor(log(max(1, $row->nbPlays), 2)));
    }

    public static function band(CsvRow $row): int
    {
        return intdiv($row->rating, self::BAND_WIDTH);
    }

    public function add(CsvRow $row): void
    {
        ++$this->total;
        foreach ($row->themes as $theme) {
            if (!isset($this->knownThemes[$theme])) {
                $this->unknownThemes[$theme] = ($this->unknownThemes[$theme] ?? 0) + 1;
            }
        }
        if (!$row->isSelectable()) {
            return;
        }

        ++$this->selectable;
        $band = self::band($row);
        $score = self::score($row);
        $this->histogram[$band][$score] = ($this->histogram[$band][$score] ?? 0) + 1;
        foreach ($row->themes as $theme) {
            if (isset($this->knownThemes[$theme])) {
                $this->themeCounts[$theme] = ($this->themeCounts[$theme] ?? 0) + 1;
                ++$this->themeLinks;
            }
        }
    }

    /**
     * @param int $target          selectable puzzles to keep (rare themes may add a few more)
     * @param int $rareThemeLimit a theme with fewer selectable puzzles than this is kept whole
     */
    public function plan(int $target, int $rareThemeLimit): SubsetPlan
    {
        $rareThemes = array_filter($this->themeCounts, static fn (int $count): bool => $count < $rareThemeLimit);
        ksort($rareThemes);

        $thresholds = [];
        $available = [];
        $quotas = [];
        foreach ($this->histogram as $band => $scores) {
            $available[$band] = array_sum($scores);
            $quota = $target >= $this->selectable
                ? $available[$band]
                : (int) round($target * $available[$band] / $this->selectable);
            $quotas[$band] = $quota;
            $thresholds[$band] = self::threshold($scores, $quota);
        }
        ksort($available);
        ksort($quotas);

        return new SubsetPlan(
            $thresholds,
            array_fill_keys(array_keys($rareThemes), true),
            $available,
            $quotas,
            $rareThemes,
            $this->unknownThemes,
            $this->total,
            $this->selectable,
            $this->selectable > 0 ? $this->themeLinks / $this->selectable : 0.0,
        );
    }

    /**
     * The lowest score kept in a band, and the share of puzzles kept at exactly that score, so the
     * band keeps `quota` puzzles.
     *
     * @param array<int, int> $scores counts by score
     *
     * @return array{int, float} score, share kept at that score
     */
    private static function threshold(array $scores, int $quota): array
    {
        krsort($scores);
        $kept = 0;
        foreach ($scores as $score => $count) {
            if ($kept + $count >= $quota) {
                return [$score, $count > 0 ? ($quota - $kept) / $count : 0.0];
            }
            $kept += $count;
        }

        return [\PHP_INT_MIN, 1.0];
    }
}
