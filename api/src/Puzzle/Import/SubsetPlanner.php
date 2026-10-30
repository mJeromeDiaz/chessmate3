<?php

declare(strict_types=1);

namespace App\Puzzle\Import;

use App\Puzzle\Theme\ThemeCatalog;

/**
 * First pass of the import: counts the selectable puzzles of the export, by rating band and
 * quality score, and by theme (a few KB, whatever the export size), then derives the
 * {@see SubsetPlan} that keeps about `target` of them (docs/PUZZLE_IMPORT.md).
 *
 * The subset is balanced: the bands from 2500 are kept whole (strong players' puzzles are few),
 * the others share the rest of the target in proportion to their selectable puzzles, the bands
 * below 700 at half weight (beginners have plenty). Within a band, the best ones first
 * (popularity, then play count), and every puzzle of a rare theme is kept, so neither strong
 * players nor rare themes run dry.
 */
final class SubsetPlanner
{
    public const BAND_WIDTH = 100;

    /** first band kept whole (2500–2599) */
    public const FULL_FROM_BAND = 25;

    /** the bands below this one (under 700) weigh {@see LOW_WEIGHT} in the share of the target */
    public const LOW_BELOW_BAND = 7;

    public const LOW_WEIGHT = 0.5;

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

        $available = array_map(array_sum(...), $this->histogram);
        ksort($available);
        $quotas = $target >= $this->selectable ? $available : self::quotas($available, $target);
        ksort($quotas);
        $thresholds = [];
        foreach ($this->histogram as $band => $scores) {
            $thresholds[$band] = self::threshold($scores, $quotas[$band]);
        }

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
     * Puzzles kept by band: the bands from {@see FULL_FROM_BAND} whole, the target's remainder
     * shared by weight among the others. A band whose weighted share exceeds its stock keeps it
     * all, and what it leaves is shared again among the rest.
     *
     * @param array<int, int> $available selectable puzzles by band
     *
     * @return array<int, int>
     */
    private static function quotas(array $available, int $target): array
    {
        $quotas = [];
        $weights = [];
        foreach ($available as $band => $count) {
            if ($band >= self::FULL_FROM_BAND) {
                $quotas[$band] = $count;
                $target -= $count;
            } else {
                $weights[$band] = $band < self::LOW_BELOW_BAND ? self::LOW_WEIGHT : 1.0;
            }
        }
        $target = max(0, $target);

        do {
            $weighted = 0.0;
            foreach ($weights as $band => $weight) {
                $weighted += $weight * $available[$band];
            }
            $ratio = $weighted > 0.0 ? $target / $weighted : 0.0;
            $capped = false;
            foreach ($weights as $band => $weight) {
                if ($weight * $ratio >= 1.0) {
                    $quotas[$band] = $available[$band];
                    $target -= $available[$band];
                    unset($weights[$band]);
                    $capped = true;
                }
            }
        } while ($capped);

        foreach ($weights as $band => $weight) {
            $quotas[$band] = (int) round($ratio * $weight * $available[$band]);
        }

        return $quotas;
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
