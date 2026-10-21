<?php

declare(strict_types=1);

namespace App\Tests\Unit\Calculation;

use App\Calculation\Drawing\Question;
use App\Calculation\Drawing\QuestionDrawer;
use App\Chess\Attacks;
use App\Chess\Rules;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The question once a line is played (docs/CALCULATION.md, § 2 bis), on GlnnF's mate in 2: after
 * Rxh4+ gxh4 Qg2#, the queen stands on g2, h4 holds a white pawn, h8 and d5 are empty.
 */
final class QuestionDrawerTest extends TestCase
{
    private const START = '7r/p3kp2/1p3p2/2Pqp1r1/7P/P3Q1P1/5P1K/2R1R3 w - - 0 36';
    private const SOLUTION = ['h8h4', 'g3h4', 'd5g2'];

    public function testItAsksOnThePositionTheSolutionReaches(): void
    {
        $rules = Rules::fromFen(self::START);
        $rules->playUci('c5c6');
        $fen = $rules->fen();
        $final = Rules::fromFen($fen);
        foreach (self::SOLUTION as $uci) {
            $final->playUci($uci);
        }
        $board = Attacks::board($final->fen());

        $asks = [];
        for ($seed = 0; $seed < 40; ++$seed) {
            $question = QuestionDrawer::draw($fen, self::SOLUTION, new Randomizer(new Mt19937($seed)));
            self::assertSame($final->fen(), $question->fen);
            $asks[$question->ask] = true;
            if (Question::WHERE === $question->ask) {
                self::assertContains($question->target, ['r', 'P', 'q'], 'A piece the line moved.');
                self::assertSame(array_keys(array_filter($board, static fn (string $p): bool => $p === $question->target)), $question->expected);
            } else {
                self::assertContains($question->target, ['h8', 'h4', 'g3', 'd5', 'g2']);
                self::assertSame(isset($board[$question->target]) ? [$board[$question->target]] : [], $question->expected);
            }
        }
        self::assertSame([Question::WHAT => true, Question::WHERE => true], array_intersect_key([Question::WHAT => true, Question::WHERE => true], $asks), 'Both, by turns.');
    }
}
