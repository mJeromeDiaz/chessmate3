<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blindfold;

use App\Blindfold\Recall\Grader;
use App\Blindfold\Recall\Placement;
use App\Blindfold\Recall\RecallRules;
use App\Enum\Activity\ExerciseType;
use App\Enum\Blindfold\RecallStatus;
use App\Enum\Training\Module;
use App\Gamification\Xp\XpRules;
use PHPUnit\Framework\TestCase;

/**
 * Position recall's levels, correction and XP (docs/BLINDFOLD_RECALL.md).
 */
final class RecallRulesTest extends TestCase
{
    public function testTwentyLevelsInFiveStagesOfFour(): void
    {
        self::assertSame(range(1, 20), array_keys(RecallRules::LEVELS));
        self::assertSame(RecallRules::MAX_LEVEL, \count(RecallRules::LEVELS));
        foreach (array_keys(RecallRules::LEVELS) as $level) {
            self::assertSame(intdiv($level - 1, 4) + 1, RecallRules::stage($level), "level $level");
        }
        // Generated up to 12, puzzles from 13; moves announced at 19 and 20 only.
        foreach (array_keys(RecallRules::LEVELS) as $level) {
            $rules = RecallRules::level($level);
            self::assertSame($level >= 13, null !== $rules['puzzlePieces'], "level $level");
            self::assertSame($level >= 13, [] === $rules['pieces'], "level $level");
            self::assertSame(match ($level) { 19 => 1, 20 => 3, default => 0 }, $rules['announced'], "level $level");
        }
        self::assertSame([8, 12, 15, 15, 5], array_map(static fn (int $level): int => RecallRules::level($level)['visibleSeconds'], [1, 5, 9, 13, 17]));
        self::assertFalse(RecallRules::exists(0));
        self::assertFalse(RecallRules::exists(21));
    }

    public function testTheFirstLevelsAddOnePieceOrOneKindAtATime(): void
    {
        $pieces = static fn (int $level): int => array_sum(array_map(static fn (string $token): int => \strlen(ltrim($token, '?')), RecallRules::level($level)['pieces'])) + 2;
        self::assertSame([3, 4, 6, 6, 7, 8, 7, 8, 8, 10, 10, 11], array_map($pieces, range(1, 12)));
        self::assertSame(['P'], RecallRules::level(1)['pieces']);
        self::assertSame(['P', 'P', 'P', 'p'], RecallRules::level(3)['pieces']);
        self::assertSame([14, 14], RecallRules::level(13)['puzzlePieces']);
        self::assertSame([16, 20], RecallRules::level(18)['puzzlePieces']);
    }

    public function testARebuildIsCorrectedSquareBySquare(): void
    {
        $expected = Placement::squares('4k3/8/8/8/3P4/8/8/4K3 w - - 0 1');

        $exact = Grader::grade($expected, ['e1' => 'K', 'e8' => 'k', 'd4' => 'P']);
        self::assertSame([RecallStatus::Exact, 3, 0, 0, 0, 1.0], [$exact->status, $exact->correct, $exact->wrong, $exact->extra, $exact->missing, $exact->accuracy]);

        // The wrong colour on the right square: wrong and missing, not extra.
        $colour = Grader::grade($expected, ['e1' => 'K', 'e8' => 'k', 'd4' => 'p']);
        self::assertSame([RecallStatus::Missed, 2, 1, 0, 1, 0.67], [$colour->status, $colour->correct, $colour->wrong, $colour->extra, $colour->missing, $colour->accuracy]);

        // All right, one piece more: not exact.
        $extra = Grader::grade($expected, ['e1' => 'K', 'e8' => 'k', 'd4' => 'P', 'a1' => 'R']);
        self::assertSame([RecallStatus::Missed, 3, 1, 1, 0, 0.75], [$extra->status, $extra->correct, $extra->wrong, $extra->extra, $extra->missing, $extra->accuracy]);

        $nothing = Grader::grade($expected, []);
        self::assertSame([RecallStatus::Missed, 0, 3, 0.0], [$nothing->status, $nothing->correct, $nothing->missing, $nothing->accuracy]);
    }

    public function testCloseFromEightyPercent(): void
    {
        $expected = Placement::squares('r3k3/pp6/8/8/8/8/PP6/R3K3 w - - 0 1');
        $placed = $expected;
        unset($placed['b7']);
        $close = Grader::grade($expected, $placed);
        self::assertSame([RecallStatus::Close, 7, 1, 0.88], [$close->status, $close->correct, $close->missing, $close->accuracy]);

        $placed['b6'] = 'p';
        unset($placed['a7']);
        self::assertSame(RecallStatus::Missed, Grader::grade($expected, $placed)->status, '6 / (8 + 1) = 0.67');
        self::assertSame(RecallStatus::Close, RecallRules::status(8, 10, 8, 0.8));
        self::assertSame(RecallStatus::Missed, RecallRules::status(7, 10, 7, 0.79));
    }

    public function testAPlacementReadsAndWritesBack(): void
    {
        $placement = 'r1bqk2r/pppp1ppp/2n2n2/2b1p3/2B1P3/3P1N2/PPP2PPP/RNBQK2R';
        $squares = Placement::squares($placement.' w KQkq - 0 1');
        self::assertCount(32, $squares);
        self::assertSame(['a8' => 'r', 'c8' => 'b'], \array_slice($squares, 0, 2));
        self::assertSame($placement, Placement::fromSquares($squares));
        self::assertSame('8/8/8/8/8/8/8/8', Placement::fromSquares([]));
    }

    public function testXpByStageAndTheUnlockBonusOnce(): void
    {
        self::assertSame(4, XpRules::exercise(ExerciseType::BlindfoldRecall, true, 10_000, 1, ['status' => 'exact', 'stage' => 1, 'unlocked' => false]));
        self::assertSame(12 + XpRules::RECALL_UNLOCK, XpRules::exercise(ExerciseType::BlindfoldRecall, true, 10_000, 1, ['status' => 'exact', 'stage' => 5, 'unlocked' => true]));
        self::assertSame(8, XpRules::exercise(ExerciseType::BlindfoldRecall, true, 10_000, 1, ['status' => 'exact', 'stage' => 3]));
        self::assertSame(XpRules::RECALL_CLOSE, XpRules::exercise(ExerciseType::BlindfoldRecall, false, 10_000, 1, ['status' => 'close', 'stage' => 4]));
        self::assertSame(0, XpRules::exercise(ExerciseType::BlindfoldRecall, false, 10_000, 1, ['status' => 'missed', 'stage' => 4]));
        self::assertSame(Module::BlindfoldRecall, XpRules::module(ExerciseType::BlindfoldRecall));
    }
}
