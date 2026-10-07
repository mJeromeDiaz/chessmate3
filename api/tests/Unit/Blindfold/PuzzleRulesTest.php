<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blindfold;

use App\Blindfold\Puzzle\PuzzleRules;
use App\Enum\Blindfold\PuzzleLevel;
use PHPUnit\Framework\TestCase;

/**
 * The rules of blindfold puzzles (docs/BLINDFOLD.md).
 */
final class PuzzleRulesTest extends TestCase
{
    public function testEveryLevelHasARatingRangeAndNoLengthIsOneMove(): void
    {
        foreach (PuzzleLevel::cases() as $level) {
            [$low, $high] = PuzzleRules::ratings($level);
            self::assertLessThan($high, $low, $level->value);
        }
        self::assertSame(PuzzleLevel::values(), array_keys(PuzzleRules::LEVELS));
        self::assertNotContains('oneMove', PuzzleRules::LENGTHS);
        self::assertGreaterThanOrEqual(2, min(array_keys(PuzzleRules::LENGTHS)));
    }

    public function testTheFrontReadsTheRules(): void
    {
        self::assertSame([
            'levels' => [
                ['key' => 'easy', 'min' => 600, 'max' => 1000],
                ['key' => 'medium', 'min' => 1000, 'max' => 1400],
                ['key' => 'hard', 'min' => 1400, 'max' => 1800],
            ],
            'lengths' => [2, 3, 4],
            'visibleSeconds' => [5, 10, 15, 20, 30],
            'hiddenSeconds' => 3,
            'peeks' => 1,
        ], PuzzleRules::toArray());
    }
}
