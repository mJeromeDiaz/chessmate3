<?php

declare(strict_types=1);

namespace App\Puzzle\Import;

/**
 * Size of the catalogue's database (data and indexes), the shared host capping each database at
 * 1 GB (docs/DEPLOY_OVH.md, § 3). `app:puzzle:rebuild-selection` rebuilds the membership table in
 * place, so the peak is the final size.
 *
 * Per-row sizes measured after importing the real export (2026-10-06, 1.5M puzzles kept out of
 * 4.06M): `puzzle` 418 MB with its indexes, `puzzle_theme_membership` 236 MB for 6.56M rows.
 */
final class SizeEstimate
{
    public const PUZZLE_ROW_BYTES = 292;
    public const MEMBERSHIP_ROW_BYTES = 38;

    public static function peakBytes(int $puzzles, int $selectable, float $themesPerPuzzle): int
    {
        $memberships = (int) ceil($selectable * $themesPerPuzzle);

        return $puzzles * self::PUZZLE_ROW_BYTES + $memberships * self::MEMBERSHIP_ROW_BYTES;
    }
}
