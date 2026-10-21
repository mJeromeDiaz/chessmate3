<?php

declare(strict_types=1);

namespace App\Tests\Functional\Calculation;

use App\Calculation\Drawing\Forcing;
use App\Chess\Rules;
use App\Entity\Catalog\Puzzle;
use App\Entity\User;
use App\Puzzle\Selection\SelectionRebuilder;
use App\Tests\Unit\Calculation\EngineKindsTest;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;

/**
 * The calculation module on its path (docs/CALCULATION.md): a mate thought over on a frozen board,
 * « Je réponds », then its whole line played at once within 2 s per half-move of the solution, on
 * the server's clock; late or not played, a failure; never outside the mind map.
 *
 * @phpstan-type Item array{id: string, type: string, data: array<string, mixed>}
 */
final class CalculationPathTest extends WoodpeckerWebTestCase
{
    public function testALessonOfMatesInOne(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $runId = $this->startStep($alice, '1-1');

        for ($i = 0; $i < 10; ++$i) {
            $item = $this->item($alice, $runId);
            if (0 === $i) {
                self::assertSame(['calculation', 1, 120, 2, 1, 10, null, null], [
                    $item['type'], $item['data']['mate'] ?? null, $item['data']['thinkSeconds'] ?? null, $item['data']['plySeconds'] ?? null,
                    $item['data']['position'] ?? null, $item['data']['count'] ?? null, $item['data']['lives'] ?? null, $item['data']['readyAt'] ?? null,
                ]);
                $ready = $this->ready($alice, $runId, $item['id']);
                self::assertIsString($ready['readyAt'] ?? null);
                self::assertSame($ready, $this->ready($alice, $runId, $item['id']), 'Ready twice: the first stamp stays.');
            } else {
                $this->ready($alice, $runId, $item['id']);
            }
            $this->travel('+1 second');
            $report = match ($i) {
                8 => ['moves' => [$this->otherMove($item)]],
                9 => ['pass' => true],
                default => ['moves' => $this->expected($item)],
            };
            $result = $this->submitOk($alice, $runId, $item['id'], $report);
            $data = \is_array($result['data'] ?? null) ? $result['data'] : [];
            self::assertSame(
                match ($i) {
                    8 => [false, 'wrong', 0],
                    9 => [false, 'late', null],
                    default => [true, 'right', null],
                },
                [$result['success'] ?? null, $data['status'] ?? null, $data['mistakeAt'] ?? null],
                (string) $i,
            );
        }

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['passed', 10, 8], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null], '80 % right.');
    }

    public function testTheAnswerHasTwoSecondsPerHalfMove(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        self::assertSame(200, $this->api('POST', '/api/mind-map/calculation/steps/1-1/skip', $alice)->getStatusCode());
        $runId = $this->startStep($alice, '1-2');

        // A mate in 2, 3 half-moves: 6 s, one more second and the 2 s of the network.
        $item = $this->item($alice, $runId);
        self::assertSame(2, $item['data']['mate'] ?? null);
        $this->ready($alice, $runId, $item['id']);
        $this->travel('+10 seconds');
        $late = $this->submitOk($alice, $runId, $item['id'], ['moves' => $this->expected($item)]);
        self::assertSame([false, 'late'], [$late['success'] ?? null, \is_array($late['data'] ?? null) ? $late['data']['status'] ?? null : null], 'Right, but late.');

        // Cut short: wrong where it stops.
        $item = $this->item($alice, $runId);
        $this->ready($alice, $runId, $item['id']);
        $short = $this->submitOk($alice, $runId, $item['id'], ['moves' => \array_slice($this->expected($item), 0, 1)]);
        self::assertSame([false, 'wrong', 1], [$short['success'] ?? null, \is_array($short['data'] ?? null) ? $short['data']['status'] ?? null : null, \is_array($short['data'] ?? null) ? $short['data']['mistakeAt'] ?? null : null]);

        // Never ready: the answer's clock started at the end of the 120 s of thinking.
        $item = $this->item($alice, $runId);
        $this->travel('+125 seconds');
        self::assertTrue($this->submitOk($alice, $runId, $item['id'], ['moves' => $this->expected($item)])['success'] ?? null);

        // A half-move the board would not let through.
        $item = $this->item($alice, $runId);
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $alice, ['itemId' => $item['id'], 'moves' => ['a1a1']]);
        self::assertSame(400, $response->getStatusCode(), (string) $response->getContent());
    }

    public function testTheMiniBossHasTwoLives(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        foreach (['1-1', '1-2', '1-3', '1-4'] as $key) {
            self::assertSame(200, $this->api('POST', '/api/mind-map/calculation/steps/'.$key.'/skip', $alice)->getStatusCode(), $key);
        }
        $runId = $this->startStep($alice, '1-mini');

        $item = $this->item($alice, $runId);
        self::assertSame([3, 4, 2], [$item['data']['mate'] ?? null, $item['data']['count'] ?? null, $item['data']['lives'] ?? null]);
        $this->submitOk($alice, $runId, $item['id'], ['pass' => true]);
        $item = $this->item($alice, $runId);
        $last = $this->submitOk($alice, $runId, $item['id'], ['pass' => true]);
        self::assertSame(0, \is_array($last['data'] ?? null) ? $last['data']['lives'] ?? null : null);

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['failed', 2, 0, false], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['completed'] ?? null]);
    }

    public function testTheMetronomeIsWonByTwelveInARow(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $runId = $this->startStep($alice, '1-boss');

        $item = $this->item($alice, $runId);
        self::assertSame([null, 40, 0, 12, 3], [
            \array_key_exists('mate', $item['data']) ? $item['data']['mate'] : 'missing', $item['data']['thinkSeconds'] ?? null, $item['data']['streak'] ?? null,
            $item['data']['streakTarget'] ?? null, $item['data']['lives'] ?? null,
        ], 'Its length never told.');
        for ($i = 0; $i < 3; ++$i) {
            $item = 0 === $i ? $item : $this->item($alice, $runId);
            $this->ready($alice, $runId, $item['id']);
            self::assertTrue($this->submitOk($alice, $runId, $item['id'], ['moves' => $this->expected($item)])['success'] ?? null);
        }
        $item = $this->item($alice, $runId);
        self::assertSame([31, 3], [$item['data']['thinkSeconds'] ?? null, $item['data']['streak'] ?? null], '3 s less per line right.');

        // A failure: the streak starts over, a life lost; the tempo keeps its pace.
        $broken = $this->submitOk($alice, $runId, $item['id'], ['pass' => true]);
        self::assertSame([0, 2], [\is_array($broken['data'] ?? null) ? $broken['data']['streak'] ?? null : null, \is_array($broken['data'] ?? null) ? $broken['data']['lives'] ?? null : null]);

        for ($i = 0; $i < 12; ++$i) {
            $item = $this->item($alice, $runId);
            self::assertSame($i, $item['data']['streak'] ?? null);
            $this->ready($alice, $runId, $item['id']);
            $this->submitOk($alice, $runId, $item['id'], ['moves' => $this->expected($item)]);
        }

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['passed', 16, 15, true], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['completed'] ?? null]);
        self::assertSame(200, \is_array($outcome['chest'] ?? null) ? $outcome['chest']['crystals'] ?? null : null);
    }

    public function testAMetronomeStoppedShortIsLost(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $runId = $this->startStep($alice, '1-boss');
        for ($i = 0; $i < 12; ++$i) {
            $item = $this->item($alice, $runId);
            $this->ready($alice, $runId, $item['id']);
            // Right, but the streak broken once in the middle: 11 right, 12 lines, a life lost.
            $this->submitOk($alice, $runId, $item['id'], 5 === $i ? ['pass' => true] : ['moves' => $this->expected($item)]);
        }
        self::assertSame(200, $this->api('POST', '/api/training/runs/'.$runId.'/stop', $alice)->getStatusCode());

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['failed', false], [$outcome['outcome'] ?? null, $outcome['completed'] ?? null], '12 lines, 11 right: no streak of 12.');
    }

    public function testChapterTwoWinsMaterialInThreeMoves(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        // The Métronome beaten: chapter 2 opens.
        $this->beatMetronome($alice);
        $runId = $this->startStep($alice, '2-1');

        $item = $this->item($alice, $runId);
        self::assertSame([null, 3, 'material', 10], [$item['data']['mate'] ?? null, $item['data']['moves'] ?? null, $item['data']['ending'] ?? null, $item['data']['count'] ?? null]);
        self::assertCount(5, $this->expected($item), '3 moves of the player: 5 half-moves.');
        $this->ready($alice, $runId, $item['id']);
        $right = $this->submitOk($alice, $runId, $item['id'], ['moves' => $this->expected($item)]);
        self::assertSame([true, 'right', 3, 'material'], [$right['success'] ?? null, \is_array($right['data'] ?? null) ? $right['data']['status'] ?? null : null, \is_array($right['data'] ?? null) ? $right['data']['moves'] ?? null : null, \is_array($right['data'] ?? null) ? $right['data']['ending'] ?? null : null]);

        // Its last move left out: wrong where it stops.
        $item = $this->item($alice, $runId);
        $this->ready($alice, $runId, $item['id']);
        $short = $this->submitOk($alice, $runId, $item['id'], ['moves' => \array_slice($this->expected($item), 0, 4)]);
        self::assertSame([false, 4], [$short['success'] ?? null, \is_array($short['data'] ?? null) ? $short['data']['mistakeAt'] ?? null : null]);
    }

    public function testALineThenAQuestionOnThePositionItReaches(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->beatMetronome($alice);
        foreach (['2-1', '2-2', '2-3', '2-4', '2-5', '2-6'] as $key) {
            self::assertSame(200, $this->api('POST', '/api/mind-map/calculation/steps/'.$key.'/skip', $alice)->getStatusCode(), $key);
        }
        $runId = $this->startStep($alice, '2-7');

        $line = $this->item($alice, $runId);
        self::assertSame(['line', true, 3], [$line['data']['kind'] ?? null, $line['data']['question'] ?? null, $line['data']['moves'] ?? null]);
        $this->ready($alice, $runId, $line['id']);
        self::assertTrue($this->submitOk($alice, $runId, $line['id'], ['moves' => $this->expected($line)])['success'] ?? null);

        // Its question: on an empty board, the position reached kept on the server.
        $question = $this->item($alice, $runId);
        self::assertSame(['question', 2], [$question['data']['kind'] ?? null, $question['data']['position'] ?? null]);
        self::assertArrayNotHasKey('fen', $question['data']);
        self::assertContains($question['data']['ask'] ?? null, ['where', 'what']);
        $answer = $this->rightAnswer($question);
        $result = $this->submitOk($alice, $runId, $question['id'], $answer);
        self::assertSame([true, 'question'], [$result['success'] ?? null, \is_array($result['data'] ?? null) ? $result['data']['kind'] ?? null : null]);

        // The next line, then a wrong answer to its question.
        $line = $this->item($alice, $runId);
        self::assertSame('line', $line['data']['kind'] ?? null);
        $this->ready($alice, $runId, $line['id']);
        $this->submitOk($alice, $runId, $line['id'], ['moves' => $this->expected($line)]);
        $question = $this->item($alice, $runId);
        $wrong = 'where' === ($question['data']['ask'] ?? null) ? ['squares' => [$this->emptySquare($question)]] : ['placement' => [['square' => $question['data']['target'], 'piece' => 'Q' === ($this->expected($question)[0] ?? null) ? 'N' : 'Q']]];
        self::assertFalse($this->submitOk($alice, $runId, $question['id'], $wrong)['success'] ?? null);
    }

    public function testTheMiniBossOfChapterTwoIsSolvedThenReplayed(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->beatMetronome($alice);
        foreach (['2-1', '2-2', '2-3', '2-4', '2-5', '2-6', '2-7', '2-8'] as $key) {
            self::assertSame(200, $this->api('POST', '/api/mind-map/calculation/steps/'.$key.'/skip', $alice)->getStatusCode(), $key);
        }
        $runId = $this->startStep($alice, '2-mini');

        // Solved first, its solution given as a puzzle's; 5 minutes before the replay's clock.
        $item = $this->item($alice, $runId);
        self::assertSame([true, $this->expected($item), 300, 4, 3], [
            $item['data']['solve'] ?? null, $item['data']['solution'] ?? null, $item['data']['thinkSeconds'] ?? null,
            $item['data']['count'] ?? null, $item['data']['lives'] ?? null,
        ]);
        $this->travel('+200 seconds');
        $this->ready($alice, $runId, $item['id']);
        self::assertTrue($this->submitOk($alice, $runId, $item['id'], ['moves' => $this->expected($item)])['success'] ?? null, 'Replayed in its time after a long solving.');

        // Not solved: a failure, nothing to replay.
        $item = $this->item($alice, $runId);
        $unsolved = $this->submitOk($alice, $runId, $item['id'], ['moves' => [], 'solutionShown' => true]);
        self::assertSame([false, 'wrong', 2], [$unsolved['success'] ?? null, \is_array($unsolved['data'] ?? null) ? $unsolved['data']['status'] ?? null : null, \is_array($unsolved['data'] ?? null) ? $unsolved['data']['lives'] ?? null : null]);
    }

    public function testOnlyThePathPlaysIt(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        foreach ([['mate' => 1, 'count' => 10], ['mate' => 1, 'pathStep' => 'calculation:1-1']] as $config) {
            $response = $this->api('POST', '/api/training/runs', $alice, [
                'module' => 'calculation',
                'subjectId' => $alice->getId()->toRfc4122(),
                'budgetSeconds' => 1_800,
                'config' => $config,
            ]);
            self::assertSame(422, $response->getStatusCode(), json_encode($config, \JSON_THROW_ON_ERROR));
        }

        // Another player's item: not found.
        $runId = $this->startStep($alice, '1-1');
        $item = $this->item($alice, $runId);
        $bob = $this->createUserIn('bob@example.com');
        self::assertSame(404, $this->api('POST', '/api/training/runs/'.$runId.'/ready', $bob, ['itemId' => $item['id']])->getStatusCode());
    }

    public function testChapterThreeTakesTheEnginesVerdictOnALegalLine(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->openChapterThree($alice);

        // 3.1: the best of two replies, the player's move shown played.
        $runId = $this->startStep($alice, '3-1');
        $item = $this->item($alice, $runId);
        $data = $item['data'];
        $solution = $this->expected($item);
        self::assertSame(['reply', true, $solution, 50, 150], [$data['kind'] ?? null, $data['engine'] ?? null, $data['solution'] ?? null, $data['toleranceCp'] ?? null, $data['decoyGapCp'] ?? null]);
        self::assertSame(400, $this->api('POST', '/api/training/runs/'.$runId.'/submission', $alice, ['itemId' => $item['id'], 'choice' => $solution[1]])->getStatusCode(), 'No verdict.');
        self::assertSame(400, $this->api('POST', '/api/training/runs/'.$runId.'/submission', $alice, ['itemId' => $item['id'], 'choice' => 'a1a1', 'engine' => ['right' => true]])->getStatusCode(), 'Not a reply.');
        $result = $this->submitOk($alice, $runId, $item['id'], ['choice' => $solution[1], 'engine' => ['right' => false]]);
        self::assertSame([true, 'right'], [$result['success'] ?? null, self::dataOf($result)['status'] ?? null], 'The solution\'s reply, whatever the engine says.');

        $item = $this->item($alice, $runId);
        $fen = self::fenOf($item);
        $solution = $this->expected($item);
        $other = self::otherReply($fen, $solution[0], $solution[1]);
        $result = $this->submitOk($alice, $runId, $item['id'], ['choice' => $other, 'engine' => ['right' => false]]);
        self::assertSame([false, 'wrong', 1], [$result['success'] ?? null, self::dataOf($result)['status'] ?? null, self::dataOf($result)['mistakeAt'] ?? null]);
        $item = $this->item($alice, $runId);
        $solution = $this->expected($item);
        $this->travel('+125 seconds');
        $result = $this->submitOk($alice, $runId, $item['id'], ['choice' => $solution[1], 'engine' => ['right' => true]]);
        self::assertSame('late', self::dataOf($result)['status'] ?? null, 'Chosen after the thinking time.');

        self::assertSame(200, $this->api('POST', '/api/training/runs/'.$runId.'/stop', $alice)->getStatusCode());

        // 3.3: a mate threat to parry, any line the engine finds as good.
        self::assertSame(200, $this->api('POST', '/api/mind-map/calculation/steps/3-1/skip', $alice)->getStatusCode());
        self::assertSame(200, $this->api('POST', '/api/mind-map/calculation/steps/3-2/skip', $alice)->getStatusCode());
        $runId = $this->startStep($alice, '3-3');
        $item = $this->item($alice, $runId);
        self::assertSame('defence', $item['data']['kind'] ?? null);
        $fen = self::fenOf($item);
        $solution = $this->expected($item);
        $this->ready($alice, $runId, $item['id']);
        self::assertTrue($this->submitOk($alice, $runId, $item['id'], ['moves' => $solution, 'engine' => ['right' => false]])['success'] ?? null, 'The solution.');

        $item = $this->item($alice, $runId);
        $fen = self::fenOf($item);
        $solution = $this->expected($item);
        $other = self::completeLine($fen, [self::otherFirst($fen, $solution[0])], \count($solution));
        $this->ready($alice, $runId, $item['id']);
        self::assertTrue($this->submitOk($alice, $runId, $item['id'], ['moves' => $other, 'engine' => ['right' => true]])['success'] ?? null, 'Another line the engine finds as good.');

        $item = $this->item($alice, $runId);
        $fen = self::fenOf($item);
        $solution = $this->expected($item);
        $other = self::completeLine($fen, [self::otherFirst($fen, $solution[0])], \count($solution));
        $this->ready($alice, $runId, $item['id']);
        $result = $this->submitOk($alice, $runId, $item['id'], ['moves' => $other, 'engine' => ['right' => false, 'mistakeAt' => 0]]);
        self::assertSame([false, 'wrong', 0], [$result['success'] ?? null, self::dataOf($result)['status'] ?? null, self::dataOf($result)['mistakeAt'] ?? null]);
        self::assertSame(400, $this->api('POST', '/api/training/runs/'.$runId.'/submission', $alice, ['itemId' => $this->item($alice, $runId)['id'], 'moves' => ['a1a1'], 'engine' => ['right' => true]])->getStatusCode(), 'An illegal half-move.');

        self::assertSame(200, $this->api('POST', '/api/training/runs/'.$runId.'/stop', $alice)->getStatusCode());

        // 3.4: the opponent's reply is a check.
        self::assertSame(200, $this->api('POST', '/api/mind-map/calculation/steps/3-3/skip', $alice)->getStatusCode());
        $runId = $this->startStep($alice, '3-4');
        $item = $this->item($alice, $runId);
        $fen = self::fenOf($item);
        $solution = $this->expected($item);
        $after = Rules::fromFen($fen);
        $after->playUci($solution[0]);
        $after->playUci($solution[1]);
        self::assertSame(['intermezzo', true], [$item['data']['kind'] ?? null, $after->inCheck()]);
        $this->ready($alice, $runId, $item['id']);
        $result = $this->submitOk($alice, $runId, $item['id'], ['moves' => \array_slice($solution, 0, 2), 'engine' => ['right' => true]]);
        self::assertSame([false, 'wrong', 2], [$result['success'] ?? null, self::dataOf($result)['status'] ?? null, self::dataOf($result)['mistakeAt'] ?? null], 'Cut short: wrong, whatever the engine says.');
    }

    public function testThreePointTwoPlaysALineAgainstEachOfTheTwoReplies(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->openChapterThree($alice);
        self::assertSame(200, $this->api('POST', '/api/mind-map/calculation/steps/3-1/skip', $alice)->getStatusCode());
        $runId = $this->startStep($alice, '3-2');

        $item = $this->item($alice, $runId);
        $fen = self::fenOf($item);
        $solution = $this->expected($item);
        self::assertSame(['two_replies', 2, 6], [$item['data']['kind'] ?? null, $item['data']['replies'] ?? null, $item['data']['count'] ?? null]);
        $other = self::completeLine($fen, [$solution[0], self::otherReply($fen, $solution[0], $solution[1])], \count($solution));
        $this->ready($alice, $runId, $item['id']);
        self::assertSame(400, $this->api('POST', '/api/training/runs/'.$runId.'/submission', $alice, ['itemId' => $item['id'], 'lines' => [$solution, $solution], 'engine' => ['right' => true]])->getStatusCode(), 'The same reply twice.');
        self::assertSame(400, $this->api('POST', '/api/training/runs/'.$runId.'/submission', $alice, ['itemId' => $item['id'], 'lines' => [$solution], 'engine' => ['right' => true]])->getStatusCode(), 'One line.');
        $result = $this->submitOk($alice, $runId, $item['id'], ['lines' => [$solution, $other], 'engine' => ['right' => true]]);
        self::assertSame([true, 'right'], [$result['success'] ?? null, self::dataOf($result)['status'] ?? null]);
        self::assertIsArray(self::dataOf($result)['second'] ?? null);
        self::assertCount(\count($other), self::dataOf($result)['second']);

        // 2 s per half-move of each line: 2 × 2 × 3 s, one more, and the network's 2 s.
        $item = $this->item($alice, $runId);
        $fen = self::fenOf($item);
        $solution = $this->expected($item);
        $other = self::completeLine($fen, [$solution[0], self::otherReply($fen, $solution[0], $solution[1])], \count($solution));
        $this->ready($alice, $runId, $item['id']);
        $this->travel(\sprintf('+%d seconds', 4 * \count($solution) + 4));
        $result = $this->submitOk($alice, $runId, $item['id'], ['lines' => [$solution, $other], 'engine' => ['right' => true]]);
        self::assertSame('late', self::dataOf($result)['status'] ?? null);
    }

    public function testTheMiniBossOfChapterThreeRefusesAnotherCombinationAtOnce(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->openChapterThree($alice);
        foreach (['3-1', '3-2', '3-3', '3-4'] as $key) {
            self::assertSame(200, $this->api('POST', '/api/mind-map/calculation/steps/'.$key.'/skip', $alice)->getStatusCode(), $key);
        }
        $runId = $this->startStep($alice, '3-mini');

        // Bxf7+ chosen in place of Rd8+: wrong at the first half-move, whatever the engine says.
        $item = $this->item($alice, $runId);
        $solution = $this->expected($item);
        self::assertSame(['combo', true, 3, 150, 4, 3], [
            $item['data']['kind'] ?? null, $item['data']['engine'] ?? null, $item['data']['combos'] ?? null,
            $item['data']['decoyGapCp'] ?? null, $item['data']['count'] ?? null, $item['data']['lives'] ?? null,
        ]);
        $decoy = array_values(array_diff(Forcing::moves(self::fenOf($item)), [$solution[0]]))[0] ?? null;
        self::assertIsString($decoy);
        $this->ready($alice, $runId, $item['id']);
        $result = $this->submitOk($alice, $runId, $item['id'], ['moves' => [$decoy], 'engine' => ['right' => true]]);
        self::assertSame([false, 'wrong', 0, 2], [
            $result['success'] ?? null, self::dataOf($result)['status'] ?? null,
            self::dataOf($result)['mistakeAt'] ?? null, self::dataOf($result)['lives'] ?? null,
        ]);

        // The right combination, then its line.
        $item = $this->item($alice, $runId);
        $this->ready($alice, $runId, $item['id']);
        $result = $this->submitOk($alice, $runId, $item['id'], ['moves' => $this->expected($item), 'engine' => ['right' => false]]);
        self::assertSame([true, 'right'], [$result['success'] ?? null, self::dataOf($result)['status'] ?? null]);
    }

    public function testTheAvocatDuDiableJudgesTheIdeaThenItsRefutation(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->openChapterThree($alice);
        $runId = $this->startStep($alice, '3-boss');

        // The player's own move, which does not hold: a life lost, whatever its refutation.
        $item = $this->item($alice, $runId);
        $fen = self::fenOf($item);
        $solution = $this->expected($item);
        self::assertSame(['advocate', true, $solution, 50, 5, 3], [
            $item['data']['kind'] ?? null, $item['data']['engine'] ?? null, $item['data']['solution'] ?? null,
            $item['data']['toleranceCp'] ?? null, $item['data']['count'] ?? null, $item['data']['lives'] ?? null,
        ]);
        $idea = self::completeLine($fen, [self::otherFirst($fen, $solution[0])], \count($solution));
        $this->ready($alice, $runId, $item['id']);
        $result = $this->submitOk($alice, $runId, $item['id'], ['moves' => $idea, 'engine' => ['right' => false, 'mistakeAt' => 0]]);
        self::assertSame([false, 'wrong', 0, 2], [
            $result['success'] ?? null, self::dataOf($result)['status'] ?? null,
            self::dataOf($result)['mistakeAt'] ?? null, self::dataOf($result)['lives'] ?? null,
        ]);

        // The solution: right, whatever the engine says.
        $item = $this->item($alice, $runId);
        $this->ready($alice, $runId, $item['id']);
        $result = $this->submitOk($alice, $runId, $item['id'], ['moves' => $this->expected($item), 'engine' => ['right' => false]]);
        self::assertSame([true, 'right'], [$result['success'] ?? null, self::dataOf($result)['status'] ?? null]);

        // A good idea, the opponent's reply imagined too weak; then out of time: lost at 3 failures.
        $item = $this->item($alice, $runId);
        $fen = self::fenOf($item);
        $solution = $this->expected($item);
        $weak = self::completeLine($fen, [$solution[0], self::otherReply($fen, $solution[0], $solution[1])], \count($solution));
        $this->ready($alice, $runId, $item['id']);
        $result = $this->submitOk($alice, $runId, $item['id'], ['moves' => $weak, 'engine' => ['right' => false, 'mistakeAt' => 1]]);
        self::assertSame(['wrong', 1, 1], [self::dataOf($result)['status'] ?? null, self::dataOf($result)['mistakeAt'] ?? null, self::dataOf($result)['lives'] ?? null]);
        $item = $this->item($alice, $runId);
        $this->submitOk($alice, $runId, $item['id'], ['pass' => true]);

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['failed', 4, 1, false], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['completed'] ?? null]);
    }

    /** The Métronome and the Compteur beaten: chapter 3 opens; its puzzles in the catalogue, at the player's level. */
    private function openChapterThree(User $user): void
    {
        foreach (['1-boss', '2-boss'] as $boss) {
            self::getContainer()->get(Connection::class)->executeStatement(
                "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (UUID_TO_BIN(UUID()), ?, 'calculation', ?, 1, '2026-09-28 09:00:00', '2026-09-28 09:00:00')",
                [$user->getId()->toBinary(), $boss],
            );
        }
        $added = [];
        foreach ([[EngineKindsTest::TWO_REPLIES, ['short']], [EngineKindsTest::DEFENCE, ['defensiveMove', 'oneMove']], [EngineKindsTest::INTERMEZZO, ['short']], [EngineKindsTest::COMBO, ['short']]] as $i => [[$fen, $moves], $themes]) {
            $added[] = $puzzle = new Puzzle('CK3'.$i, $fen, $moves, 1350, 80, 95, 5000, $themes, 'https://lichess.org/abcdefgh', null);
            $this->catalog->persist($puzzle);
        }
        $this->catalog->flush();
        self::getContainer()->get(SelectionRebuilder::class)->addPuzzles(array_map(static fn (Puzzle $p): int => (int) $p->getId(), $added));
        $this->catalog->clear();
    }

    /**
     * @param Item $item
     */
    private static function fenOf(array $item): string
    {
        self::assertIsString($item['data']['fen'] ?? null);

        return $item['data']['fen'];
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function dataOf(array $result): array
    {
        $data = $result['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> */
        return $data;
    }

    /** A legal first move of the player's other than $first. */
    private static function otherFirst(string $fen, string $first): string
    {
        foreach (Rules::fromFen($fen)->legalMoves() as $move) {
            if (Rules::uci($move) !== $first) {
                return Rules::uci($move);
            }
        }
        self::fail('No other first move.');
    }

    /** A legal reply to $first other than $reply. */
    private static function otherReply(string $fen, string $first, string $reply): string
    {
        $rules = Rules::fromFen($fen);
        self::assertNotNull($rules->playUci($first));
        foreach ($rules->legalMoves() as $move) {
            if (Rules::uci($move) !== $reply) {
                return Rules::uci($move);
            }
        }
        self::fail('Only one reply.');
    }

    /**
     * $start continued with legal half-moves to $plies, or to the player's mate.
     *
     * @param list<string> $start
     *
     * @return list<string>
     */
    private static function completeLine(string $fen, array $start, int $plies): array
    {
        $rules = Rules::fromFen($fen);
        foreach ($start as $uci) {
            self::assertNotNull($rules->playUci($uci));
        }
        $line = $start;
        while (\count($line) < $plies && !$rules->inCheckmate()) {
            $moves = $rules->legalMoves();
            self::assertNotEmpty($moves, 'Stalemate.');
            $chosen = $moves[0];
            // Not mated by the opponent: the player's half-moves are the even ones.
            foreach ($moves as $move) {
                if (0 === \count($line) % 2 || !self::mates($rules, Rules::uci($move))) {
                    $chosen = $move;
                    break;
                }
            }
            $rules->playUci(Rules::uci($chosen));
            $line[] = Rules::uci($chosen);
        }

        return $line;
    }

    /**
     * Whether $uci mates, the position left unchanged.
     */
    private static function mates(Rules $rules, string $uci): bool
    {
        $rules->playUci($uci);
        $mates = $rules->inCheckmate();
        $rules->undo();

        return $mates;
    }

    private function beatMetronome(User $user): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (UUID_TO_BIN(UUID()), ?, 'calculation', '1-boss', 1, '2026-09-28 09:00:00', '2026-09-28 09:00:00')",
            [$user->getId()->toBinary()],
        );
    }

    /**
     * A question's right answer, from what the server keeps.
     *
     * @param Item $question
     *
     * @return array<string, mixed>
     */
    private function rightAnswer(array $question): array
    {
        $expected = $this->expected($question);
        if ('where' === ($question['data']['ask'] ?? null)) {
            return ['squares' => [$expected[0] ?? '']];
        }

        return ['placement' => [] === $expected ? [] : [['square' => $question['data']['target'], 'piece' => $expected[0]]]];
    }

    /**
     * A square the question's piece does not stand on.
     *
     * @param Item $question
     */
    private function emptySquare(array $question): string
    {
        $holding = $this->expected($question);
        foreach (['a1', 'h8', 'a8', 'h1'] as $square) {
            if (!\in_array($square, $holding, true)) {
                return $square;
            }
        }
        self::fail('No square left.');
    }

    private function startStep(User $user, string $key): string
    {
        $response = $this->api('POST', '/api/mind-map/calculation/steps/'.$key.'/start', $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);
        self::assertIsString($run['id'] ?? null);

        return $run['id'];
    }

    /**
     * @return Item
     */
    private function item(User $user, string $runId): array
    {
        $item = $this->json($this->api('POST', '/api/training/runs/'.$runId.'/next', $user))['item'] ?? null;
        self::assertIsArray($item, 'The run served no line.');

        /** @var Item */
        return $item;
    }

    /**
     * @return array<string, mixed>
     */
    private function ready(User $user, string $runId, string $itemId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/ready', $user, ['itemId' => $itemId]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $data = $this->json($response)['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> */
        return $data;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function submitOk(User $user, string $runId, string $itemId, array $report): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId] + $report);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var array<string, mixed> */
        return $result;
    }

    /**
     * The item's solution, read where the server keeps it (the client never gets it before).
     *
     * @param Item $item
     *
     * @return list<string>
     */
    private function expected(array $item): array
    {
        $json = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT expected FROM calculation_attempt WHERE id = UUID_TO_BIN(?)',
            [$item['id']],
        );
        self::assertIsString($json);
        $expected = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);

        return array_values(array_map(static fn (mixed $uci): string => \is_string($uci) ? $uci : '', $expected));
    }

    /**
     * A legal first move that is not the solution's and does not mate.
     *
     * @param Item $item
     */
    private function otherMove(array $item): string
    {
        $fen = \is_string($item['data']['fen'] ?? null) ? $item['data']['fen'] : '';
        $expected = $this->expected($item);
        foreach (Rules::fromFen($fen)->legalMoves() as $move) {
            $uci = Rules::uci($move);
            $after = Rules::fromFen($fen);
            $after->playUci($uci);
            if ($uci !== $expected[0] && !$after->inCheckmate()) {
                return $uci;
            }
        }
        self::fail('No other move.');
    }
}
