<?php

declare(strict_types=1);

namespace App\Parcours;

use App\Enum\Parcours\Theme;

/**
 * The rules of a parcours (docs/PARCOURS.md, choices of 2026-10-17): five tiers chained without a
 * pause, the effort's count of exercises in each; in a tier of puzzles, the first third below the
 * player's puzzle rating, the middle at it, the last third above. Pure.
 */
final class ParcoursRules
{
    public const TIERS = 5;

    /** Below, at and above the player's puzzle rating. */
    public const RATING_GAP = 200;

    /** A boss of the défis' path: its first puzzle that far above the player's rating, its last that far. */
    public const BOSS_GAPS = [100, 300];

    /** Half-widths of the range around a target, tried in turn; then any rating. */
    public const WIDENINGS = [75, 200, 500];

    /** The widest range, when the others have nothing left. */
    public const ANY_RATING = [0, 4000];

    /** Random draws per range, candidates per draw (as the Puzzle Streak). */
    public const DRAWS = 4;
    public const CANDIDATES = 8;

    /** An opening needs that many puzzles of its family for the player's side. */
    public const MIN_OPENING_PUZZLES = 30;

    public const LINES = 'lines';
    public const PUZZLES = 'puzzles';

    /**
     * The Lichess themes (OR) of each tier of a puzzle theme; the opening alternates lines and
     * puzzles of the repertoire's family.
     */
    private const TIER_THEMES = [
        'mates' => [['mateIn1'], ['mateIn2'], ['mateIn3'], ['mateIn4'], ['mateIn5']],
        'endgames' => [['pawnEndgame'], ['rookEndgame'], ['bishopEndgame'], ['knightEndgame'], ['queenEndgame']],
        'tactics' => [['fork'], ['pin'], ['skewer'], ['discoveredAttack'], ['deflection']],
    ];

    private const OPENING_TIERS = [self::LINES, self::PUZZLES, self::LINES, self::PUZZLES, self::LINES];

    /**
     * What a tier (0 to 4) plays: lines of the repertoire, or puzzles of themes (empty: the
     * repertoire's opening family).
     *
     * @return array{kind: string, themes: list<string>}
     */
    public static function tier(Theme $theme, int $tier): array
    {
        if ($tier < 0 || $tier >= self::TIERS) {
            throw new \InvalidArgumentException('A tier is 0 to 4.');
        }
        if (Theme::Opening === $theme) {
            return ['kind' => self::OPENING_TIERS[$tier], 'themes' => []];
        }

        return ['kind' => self::PUZZLES, 'themes' => self::TIER_THEMES[$theme->value][$tier]];
    }

    /**
     * The rating aimed at for the puzzle at $position (0-based) of a tier of $count: below for
     * the first third, at the player's for the middle, above for the last third (10: 3, 4, 3).
     */
    public static function target(int $rating, int $position, int $count): int
    {
        $third = intdiv($count, 3);
        $offset = match (true) {
            $position < $third => -self::RATING_GAP,
            $position >= $count - $third => self::RATING_GAP,
            default => 0,
        };

        return max(self::ANY_RATING[0], $rating + $offset);
    }

    /**
     * The rating aimed at for the puzzle at $position (0-based) of a boss of the défis' path
     * (docs/DEFIS.md): from {@see self::BOSS_GAPS}[0] above the player's rating for the first to
     * [1] for the last, evenly.
     */
    public static function bossTarget(int $rating, int $position, int $count): int
    {
        [$from, $to] = self::BOSS_GAPS;
        $offset = $count > 1 ? $from + (int) round(($to - $from) * min($position, $count - 1) / ($count - 1)) : $from;

        return min(self::ANY_RATING[1], $rating + $offset);
    }

    /**
     * The ranges to draw from, narrowest first: around the target, wider and wider, then any.
     *
     * @return list<array{int, int}>
     */
    public static function ranges(int $target): array
    {
        $ranges = array_map(static fn (int $half): array => [max(0, $target - $half), $target + $half], self::WIDENINGS);
        $ranges[] = self::ANY_RATING;

        return $ranges;
    }
}
