<?php

declare(strict_types=1);

namespace App\Tests\Unit\Endgame;

use App\Endgame\Lesson\Catalogue;
use App\Endgame\Pool\PositionPool;
use App\Endgame\Tablebase\Answer;
use App\Endgame\Tablebase\BestDefence;
use PHPUnit\Framework\TestCase;

final class BestDefenceTest extends TestCase
{
    public function testALostKingResistsTheLongest(): void
    {
        $answer = Answer::fromLichess(['category' => 'loss', 'dtm' => -14, 'moves' => [
            ['uci' => 'e5d6', 'category' => 'win', 'dtm' => 13],
            ['uci' => 'e5f6', 'category' => 'win', 'dtm' => 9],
            ['uci' => 'e5e6', 'category' => 'win', 'dtm' => 13],
        ]]);

        self::assertSame(['e5d6', 'e5e6'], BestDefence::moves($answer));
    }

    public function testADrawnSideKeepsTheDrawAndAWinningSideWinsFastest(): void
    {
        $draw = Answer::fromLichess(['category' => 'draw', 'moves' => [
            ['uci' => 'a6a1', 'category' => 'draw'],
            ['uci' => 'a6b6', 'category' => 'win', 'dtm' => 30],
            ['uci' => 'a6a2', 'category' => 'draw'],
        ]]);
        self::assertSame(['a6a1', 'a6a2'], BestDefence::moves($draw));

        self::assertSame(['a1a7'], BestDefence::moves(Answer::fromLichess(AnswerTest::BODY)));
        self::assertSame([], BestDefence::moves(Answer::fromLichess(['category' => 'unknown'])));
    }

    public function testThePoolTakesPositionsThatTeachSomething(): void
    {
        $philidor = Catalogue::get('philidor');
        $kqk = Catalogue::get('kqk');
        self::assertNotNull($philidor);
        self::assertNotNull($kqk);

        $allDraw = Answer::fromLichess(['category' => 'draw', 'moves' => [['uci' => 'a1a2', 'category' => 'draw']]]);
        $oneLoses = Answer::fromLichess(['category' => 'draw', 'moves' => [['uci' => 'a1a2', 'category' => 'draw'], ['uci' => 'a1a3', 'category' => 'win']]]);
        self::assertFalse(PositionPool::accepts($philidor, $allDraw), 'Nothing to hold: every move draws.');
        self::assertTrue(PositionPool::accepts($philidor, $oneLoses));
        self::assertFalse(PositionPool::accepts($philidor, Answer::fromLichess(AnswerTest::BODY)), 'A win is not a draw to hold.');

        self::assertTrue(PositionPool::accepts($kqk, Answer::fromLichess(AnswerTest::BODY)));
        self::assertFalse(PositionPool::accepts($kqk, Answer::fromLichess(['dtm' => 3] + AnswerTest::BODY)), 'Mate in two: too short.');
    }
}
