<?php

declare(strict_types=1);

namespace App\Tests\Functional\Endgame;

use App\Endgame\Judge\GameJudge;
use App\Endgame\Lesson\Catalogue;
use App\Endgame\Lesson\Lesson;
use App\Enum\Endgame\AttemptStatus;
use App\Training\Exception\InvalidItemSubmissionException;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * The whole game judged again by the server from the kept answers (docs/ENDGAMES.md, § 5), on the
 * square rule: a5-a6-a7-a8=Q, the black king too far.
 */
final class GameJudgeTest extends KernelTestCase
{
    use KeptAnswersTrait;

    private const START = '8/8/8/P7/8/8/5k2/7K w - - 0 1';
    private const GAME = ['a5a6', 'f2e3', 'a6a7', 'e3d4', 'a7a8q'];

    private GameJudge $judge;
    private Lesson $lesson;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('repertoire.explorer_cache')->clear();
        $this->judge = self::getContainer()->get(GameJudge::class);
        $this->lesson = Catalogue::get('square-promote') ?? throw new \LogicException();
    }

    public function testThePawnQueensTheWinKept(): void
    {
        $this->keepTheGame();

        $verdict = $this->judge->judge($this->lesson, self::START, self::GAME, 12, null, false, false, null);

        self::assertSame([AttemptStatus::Won, 3, null, false], [$verdict->status, $verdict->playerMoves, $verdict->mistakeAt, $verdict->engineJudged]);
    }

    public function testAMoveThatDrawsSpoilsThePosition(): void
    {
        self::keep(self::START, self::winWith('a5a6', 'h1h2'));

        $verdict = $this->judge->judge($this->lesson, self::START, ['h1h2'], 12, null, false, false, null);

        self::assertSame([AttemptStatus::Spoiled, 1, 0, 'a5a6'], [$verdict->status, $verdict->playerMoves, $verdict->mistakeAt, $verdict->bestMove]);
    }

    public function testTooManyMovesAndGivingUp(): void
    {
        $this->keepTheGame();

        self::assertSame(AttemptStatus::Overtime, $this->judge->judge($this->lesson, self::START, ['a5a6', 'f2e3', 'a6a7'], 2, null, false, false, null)->status);
        self::assertSame(AttemptStatus::Resigned, $this->judge->judge($this->lesson, self::START, ['a5a6', 'f2e3'], 12, null, false, true, null)->status);
        self::assertSame(AttemptStatus::Resigned, $this->judge->judge($this->lesson, self::START, [], 12, null, false, true, null)->status);
    }

    public function testTheBestDefenceAtTheTopOfTheSlider(): void
    {
        $this->keepTheGame();
        // After a6, Black resists the longest with Ke2 (mate in 22), not Ke3 (20).
        self::keep('8/8/P7/8/8/8/5k2/7K b - - 0 1', ['category' => 'loss', 'dtm' => -22, 'moves' => [
            ['uci' => 'f2e2', 'category' => 'win', 'dtm' => 21],
            ['uci' => 'f2e3', 'category' => 'win', 'dtm' => 19],
        ]]);

        $this->expectException(InvalidItemSubmissionException::class);
        $this->judge->judge($this->lesson, self::START, self::GAME, 12, null, true, false, null);
    }

    public function testWithoutTheTablebaseTheEngineJudges(): void
    {
        $won = $this->judge->judge($this->lesson, self::START, self::GAME, 12, null, false, false, ['right' => true]);
        self::assertSame([AttemptStatus::Won, true], [$won->status, $won->engineJudged]);
        $spoiled = $this->judge->judge($this->lesson, self::START, ['a5a6', 'f2e3', 'h1g2'], 12, null, false, false, ['right' => false, 'mistakeAt' => 2]);
        self::assertSame([AttemptStatus::Spoiled, 2, true], [$spoiled->status, $spoiled->mistakeAt, $spoiled->engineJudged]);

        $this->expectException(InvalidItemSubmissionException::class);
        $this->judge->judge($this->lesson, self::START, self::GAME, 12, null, false, false, null);
    }

    public function testADrawHeldForItsMoves(): void
    {
        $lesson = Catalogue::get('square-stop') ?? throw new \LogicException();
        $start = '8/8/8/P7/3k4/8/8/7K b - - 0 1';
        self::keep($start, self::drawWith('d4c5', 'd4d3'));
        self::keep('8/8/8/P1k5/8/8/6K1/8 b - - 0 1', self::drawWith('c5b5'));

        self::assertSame(AttemptStatus::Held, $this->judge->judge($lesson, $start, ['d4c5', 'h1g2', 'c5b5'], null, 2, false, false, null)->status);
    }

    public function testIllegalUnfinishedOrTooLongGames(): void
    {
        $this->keepTheGame();

        foreach ([['a5a7'], ['a5a6', 'f2e3'], [...self::GAME, 'd4c5']] as $moves) {
            try {
                $this->judge->judge($this->lesson, self::START, $moves, 12, null, false, false, null);
                self::fail(implode(' ', $moves).' accepted.');
            } catch (InvalidItemSubmissionException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** The player's three positions of GAME. */
    private function keepTheGame(): void
    {
        self::keep(self::START, self::winWith('a5a6', 'h1h2'));
        self::keep('8/8/P7/8/8/4k3/8/7K w - - 0 1', self::winWith('a6a7'));
        self::keep('8/P7/8/8/3k4/8/8/7K w - - 0 1', self::winWith('a7a8q'));
    }
}
