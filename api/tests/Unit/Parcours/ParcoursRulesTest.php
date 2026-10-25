<?php

declare(strict_types=1);

namespace App\Tests\Unit\Parcours;

use App\Enum\Parcours\Effort;
use App\Enum\Parcours\Theme;
use App\Gamification\Xp\XpRules;
use App\Parcours\ParcoursRules;
use PHPUnit\Framework\TestCase;

/**
 * The rules of a parcours (docs/PARCOURS.md): tiers by theme, ratings by thirds, ranges widened to
 * any rating, efforts and their bonuses.
 */
final class ParcoursRulesTest extends TestCase
{
    public function testTheTiersOfEachTheme(): void
    {
        $tiers = static fn (Theme $theme): array => array_map(static fn (int $tier): array => ParcoursRules::tier($theme, $tier), range(0, 4));

        self::assertSame([['mateIn1'], ['mateIn2'], ['mateIn3'], ['mateIn4'], ['mateIn5']], array_column($tiers(Theme::Mates), 'themes'));
        self::assertSame([['pawnEndgame'], ['rookEndgame'], ['bishopEndgame'], ['knightEndgame'], ['queenEndgame']], array_column($tiers(Theme::Endgames), 'themes'));
        self::assertSame([['fork'], ['pin'], ['skewer'], ['discoveredAttack'], ['deflection']], array_column($tiers(Theme::Tactics), 'themes'));
        self::assertSame(['lines', 'puzzles', 'lines', 'puzzles', 'lines'], array_column($tiers(Theme::Opening), 'kind'));
    }

    public function testRatingsBelowAtAndAboveByThirds(): void
    {
        $targets = static fn (int $count): array => array_map(static fn (int $p): int => ParcoursRules::target(1500, $p, $count), range(0, $count - 1));

        self::assertSame([1300, 1500, 1700], $targets(3));
        self::assertSame([1300, 1300, 1500, 1500, 1700, 1700], $targets(6));
        self::assertSame([1300, 1300, 1300, 1500, 1500, 1500, 1500, 1700, 1700, 1700], $targets(10));
        self::assertSame(0, ParcoursRules::target(100, 0, 3), 'never below 0');
    }

    public function testABossClimbsFrom100To300AboveTheRating(): void
    {
        self::assertSame(
            [1600, 1622, 1644, 1667, 1689, 1711, 1733, 1756, 1778, 1800],
            array_map(static fn (int $p): int => ParcoursRules::bossTarget(1500, $p, 10), range(0, 9)),
        );
        self::assertSame(4000, ParcoursRules::bossTarget(3900, 9, 10), 'never above 4000');
    }

    public function testRangesWidenToAnyRating(): void
    {
        self::assertSame([[1425, 1575], [1300, 1700], [1000, 2000], [0, 4000]], ParcoursRules::ranges(1500));
    }

    public function testEffortsAndTheirBonuses(): void
    {
        self::assertSame([[3, 25], [6, 50], [10, 100]], array_map(static fn (Effort $e): array => [$e->perTier(), $e->bonusXp()], Effort::cases()));
        self::assertSame([25, XpRules::SESSION_COMPLETED, XpRules::SESSION_COMPLETED], [XpRules::sessionBonus('light'), XpRules::sessionBonus(null), XpRules::sessionBonus('unknown')]);
    }
}
