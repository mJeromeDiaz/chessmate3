<?php

declare(strict_types=1);

namespace App\Tests\Unit\Endgame;

use App\Endgame\Tablebase\Answer;
use App\Enum\Endgame\Category;
use App\Enum\Endgame\Result;
use PHPUnit\Framework\TestCase;

final class AnswerTest extends TestCase
{
    /** Roi + Tour contre Roi, white to move: two moves keep the win, one gives the rook away. */
    public const BODY = [
        'category' => 'win',
        'dtz' => 13,
        'dtm' => 13,
        'checkmate' => false,
        'stalemate' => false,
        'insufficient_material' => false,
        'moves' => [
            ['uci' => 'a1a7', 'san' => 'Ra7', 'category' => 'loss', 'dtz' => -12, 'dtm' => -12, 'zeroing' => false, 'checkmate' => false, 'stalemate' => false],
            ['uci' => 'a1b1', 'san' => 'Rb1', 'category' => 'loss', 'dtz' => -16, 'dtm' => -16, 'zeroing' => false, 'checkmate' => false, 'stalemate' => false],
            ['uci' => 'a1e1', 'san' => 'Re1', 'category' => 'draw', 'dtz' => 0, 'dtm' => null, 'zeroing' => false, 'checkmate' => false, 'stalemate' => false],
        ],
    ];

    public function testLichessDescribesEachMoveForTheOpponent(): void
    {
        $answer = Answer::fromLichess(self::BODY);

        self::assertSame(Result::Win, $answer->result());
        self::assertSame(13, $answer->dtm);
        self::assertSame(Result::Win, $answer->move('a1a7')?->resultForMover());
        self::assertSame(12, $answer->move('a1a7')->pliesToMate());
        self::assertSame(Result::Draw, $answer->move('a1e1')?->resultForMover());
        self::assertNull($answer->move('h1h2'));
        self::assertSame(['a1a7', 'a1b1'], array_map(static fn ($move): string => $move->uci, $answer->keepingMoves()));
    }

    public function testTheCompactFormKeepsEverything(): void
    {
        $answer = Answer::fromLichess(self::BODY + ['checkmate' => true]);
        $stored = json_decode(json_encode($answer->toStored(), \JSON_THROW_ON_ERROR), true);
        self::assertIsArray($stored);

        self::assertEquals($answer, Answer::fromStored($stored));
    }

    public function testUncertainCategoriesHaveNoResult(): void
    {
        self::assertNull(Category::MaybeWin->result());
        self::assertNull(Category::Unknown->result());
        self::assertSame(Result::Draw, Category::CursedWin->result());
        self::assertSame(Result::Loss, Category::SyzygyLoss->result());
        self::assertSame(Category::Unknown, Answer::fromLichess(['category' => 'something new'])->category);
    }
}
