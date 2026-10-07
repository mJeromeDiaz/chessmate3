<?php

declare(strict_types=1);

namespace App\Tests\Unit\Gamification;

use App\Enum\Activity\ExerciseType;
use App\Enum\Training\Module;
use App\Gamification\Summary\Streak;
use App\Gamification\Xp\XpRules;
use PHPUnit\Framework\TestCase;

/**
 * The XP rules and the streaks (docs/GAMIFICATION.md).
 */
final class XpRulesTest extends TestCase
{
    public function testEachExerciseGainsByItsResult(): void
    {
        self::assertSame([10, 3], [XpRules::exercise(ExerciseType::PuzzleRated, true, 0), XpRules::exercise(ExerciseType::PuzzleRated, false, 0)]);
        self::assertSame([4, 1], [XpRules::exercise(ExerciseType::PuzzleUnrated, true, 0), XpRules::exercise(ExerciseType::PuzzleUnrated, false, 0)]);
        self::assertSame([8, 2], [XpRules::exercise(ExerciseType::WoodpeckerPuzzle, true, 0), XpRules::exercise(ExerciseType::WoodpeckerPuzzle, false, 0)]);
        self::assertSame([12, 4], [XpRules::exercise(ExerciseType::RepertoireSegment, true, 0), XpRules::exercise(ExerciseType::RepertoireSegment, false, 0)]);
        self::assertSame(25, XpRules::exercise(ExerciseType::FreeStudy, true, 25 * 60_000 + 59_000), 'one a minute');
        self::assertSame(60, XpRules::exercise(ExerciseType::FreeStudy, true, 3 * 3_600_000), 'at most 60 a run');
        self::assertSame([20, 20], [XpRules::exercise(ExerciseType::CoordinatesSeries, true, 300_000, 10), XpRules::exercise(ExerciseType::CoordinatesSeries, false, 300_000, 120)], 'a flat gain, validated or not');
        self::assertSame(0, XpRules::exercise(ExerciseType::CoordinatesSeries, false, 300_000, 9), 'fewer than 10 answers');
        self::assertSame(Module::Puzzles, XpRules::module(ExerciseType::PuzzleUnrated));
        self::assertSame(Module::Coordinates, XpRules::module(ExerciseType::CoordinatesSeries));
        self::assertSame([12, 6, 2], [
            XpRules::exercise(ExerciseType::BlindfoldPuzzle, true, 0, 1, ['status' => 'solved']),
            XpRules::exercise(ExerciseType::BlindfoldPuzzle, false, 0, 1, ['status' => 'helped']),
            XpRules::exercise(ExerciseType::BlindfoldPuzzle, false, 0, 1, ['status' => 'failed']),
        ], 'blindfold: solved, after a peek, failed');
        self::assertSame(Module::Blindfold, XpRules::module(ExerciseType::BlindfoldPuzzle));
    }

    public function testLevelsNeed250TimesTheLevel(): void
    {
        self::assertSame(['level' => 1, 'xpInLevel' => 0, 'xpForNext' => 250], XpRules::level(0));
        self::assertSame(['level' => 1, 'xpInLevel' => 249, 'xpForNext' => 250], XpRules::level(249));
        self::assertSame(['level' => 2, 'xpInLevel' => 0, 'xpForNext' => 500], XpRules::level(250));
        // Level 12 starts at 250 × (1 + … + 11) = 16 500 and needs 3 000 (the design's bar).
        self::assertSame(['level' => 12, 'xpInLevel' => 2340, 'xpForNext' => 3000], XpRules::level(16_500 + 2340));
        self::assertSame(['level' => 3, 'xpInLevel' => 0, 'xpForNext' => 300], XpRules::level(300, XpRules::MODULE_STEP), 'modules: 100 × N');
    }

    public function testRanks(): void
    {
        self::assertSame('Débutant', XpRules::rank(1));
        self::assertSame('Amateur', XpRules::rank(9));
        self::assertSame('Tacticien', XpRules::rank(12));
        self::assertSame(['rank' => 'Stratège', 'level' => 15], XpRules::nextRank(12));
        self::assertSame('Maître', XpRules::rank(42));
        self::assertNull(XpRules::nextRank(30));
    }

    public function testStreaksCountConsecutiveLocalDays(): void
    {
        self::assertSame(['current' => 0, 'best' => 0, 'playedToday' => false], Streak::of([], '2026-10-05'));
        $days = ['2026-09-01', '2026-09-02', '2026-09-03', '2026-09-04', '2026-10-02', '2026-10-03', '2026-10-04'];
        self::assertSame(['current' => 3, 'best' => 4, 'playedToday' => false], Streak::of($days, '2026-10-05'), 'alive until today is over');
        self::assertSame(['current' => 4, 'best' => 4, 'playedToday' => true], Streak::of([...$days, '2026-10-05'], '2026-10-05'));
        self::assertSame(['current' => 0, 'best' => 4, 'playedToday' => false], Streak::of($days, '2026-10-06'), 'a day missed');
        self::assertSame(['current' => 2, 'best' => 2, 'playedToday' => true], Streak::of(['2026-02-28', '2026-03-01'], '2026-03-01'), 'across months');
    }
}
