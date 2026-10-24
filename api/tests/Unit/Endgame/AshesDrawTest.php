<?php

declare(strict_types=1);

namespace App\Tests\Unit\Endgame;

use App\Endgame\Training\AshesDraw;
use PHPUnit\Framework\TestCase;

final class AshesDrawTest extends TestCase
{
    private const GROUPS = [['a1', 'a2'], ['b1', 'b2', 'b3'], ['c1'], ['d1', 'd2']];

    public function testOneLessonOfEachGroupThenOthersInTheGroupsOrder(): void
    {
        foreach (['run-1', 'run-2', 'run-3', 'run-4', 'run-5'] as $seed) {
            $drawn = AshesDraw::lessons(self::GROUPS, 6, $seed);

            self::assertCount(6, $drawn, $seed);
            self::assertSame($drawn, array_values(array_unique($drawn)), 'All distinct.');
            foreach (self::GROUPS as $group) {
                self::assertNotSame([], array_intersect($group, $drawn), $seed);
            }
            $order = array_merge(...self::GROUPS);
            self::assertSame(array_values(array_intersect($order, $drawn)), $drawn, 'The page\'s order.');
        }
    }

    public function testTheSameRunDrawsTheSameLessons(): void
    {
        self::assertSame(AshesDraw::lessons(self::GROUPS, 6, 'run-1'), AshesDraw::lessons(self::GROUPS, 6, 'run-1'));
        $draws = array_map(static fn (int $seed): string => implode(',', AshesDraw::lessons(self::GROUPS, 6, 'run-'.$seed)), range(1, 30));
        self::assertGreaterThan(1, \count(array_unique($draws)), 'Another run, another draw.');
    }

    public function testFewerLessonsThanAsked(): void
    {
        self::assertSame(['a1', 'a2', 'b1', 'b2', 'b3', 'c1', 'd1', 'd2'], AshesDraw::lessons(self::GROUPS, 12, 'run-1'));
        self::assertCount(2, AshesDraw::lessons(self::GROUPS, 2, 'run-1'), 'Two groups of the four.');
    }
}
