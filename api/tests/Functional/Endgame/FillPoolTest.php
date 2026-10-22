<?php

declare(strict_types=1);

namespace App\Tests\Functional\Endgame;

use App\Endgame\Lesson\Catalogue;
use App\Endgame\Pool\PositionPool;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * app:endgame:fill-pool and the positions served (docs/ENDGAMES.md, § 4).
 */
final class FillPoolTest extends KernelTestCase
{
    /** A drawn position where one move loses: a draw to hold. */
    private const HOLD = '{"category":"draw","moves":[{"uci":"b6a5","category":"draw"},{"uci":"b6c5","category":"win","dtm":30}]}';

    private int $calls = 0;
    private string $answer = self::HOLD;

    protected function setUp(): void
    {
        self::bootKernel();
        self::getContainer()->get('repertoire.explorer_cache')->clear();
        self::getContainer()->set('lichess_tablebase.client', new MockHttpClient(function (): MockResponse {
            ++$this->calls;

            return new MockResponse($this->answer, ['response_headers' => ['content-type' => 'application/json']]);
        }, 'https://tablebase.lichess.ovh'));
    }

    public function testVariantsAreCheckedOnceAndKept(): void
    {
        $tester = $this->fill(['--lesson' => 'rook-pawn', '--per-lesson' => '3']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(3, $this->calls);
        $rows = self::getContainer()->get('doctrine.dbal.endgame_connection')->fetchAllAssociative('SELECT fen, textbook FROM endgame_position WHERE lesson_key = ? ORDER BY id', ['rook-pawn']);
        self::assertSame([
            ['fen' => '8/8/1k6/8/8/8/P7/K7 b - - 0 1', 'textbook' => 1],
            ['fen' => '8/8/6k1/8/8/8/7P/7K b - - 0 1', 'textbook' => 0],
            ['fen' => 'k7/p7/8/8/8/1K6/8/8 w - - 0 1', 'textbook' => 0],
        ], array_map(static fn (array $row): array => ['fen' => $row['fen'], 'textbook' => (int) (is_numeric($row['textbook']) ? $row['textbook'] : 0)], $rows));

        // Full: nothing asked again.
        $this->fill(['--lesson' => 'rook-pawn', '--per-lesson' => '3']);
        self::assertSame(3, $this->calls);

        // Variants are served, the textbook position never at random.
        $pool = self::getContainer()->get(PositionPool::class);
        $lesson = Catalogue::get('rook-pawn');
        self::assertNotNull($lesson);
        $randomizer = new Randomizer(new Mt19937(7));
        $first = $pool->pick($lesson, $randomizer);
        self::assertNotNull($first);
        self::assertNotSame('8/8/1k6/8/8/8/P7/K7 b - - 0 1', $first['fen']);
        $second = $pool->pick($lesson, $randomizer, [$first['id']]);
        self::assertNotNull($second);
        self::assertNotSame($first['id'], $second['id']);
        self::assertNull($pool->pick($lesson, $randomizer, [$first['id'], $second['id']]));
    }

    public function testARunStopsAtItsCallBudget(): void
    {
        $tester = $this->fill(['--lesson' => 'philidor', '--max-calls' => '2']);

        $tester->assertCommandIsSuccessful();
        self::assertSame(2, $this->calls);
        self::assertStringContainsString('Stopped at 2 Lichess calls', $tester->getDisplay());
    }

    public function testRejectedAnswersAreNotKept(): void
    {
        // A win is no draw to hold.
        $this->answer = '{"category":"win","dtm":20,"moves":[{"uci":"a1a2","category":"loss","dtm":-19}]}';

        $this->fill(['--lesson' => 'rook-pawn', '--per-lesson' => '3']);

        self::assertGreaterThan(0, $this->calls);
        self::assertSame(0, self::getContainer()->get(PositionPool::class)->count(Catalogue::get('rook-pawn') ?? throw new \LogicException()));
    }

    /**
     * @param array<string, string> $options
     */
    private function fill(array $options): CommandTester
    {
        \assert(null !== self::$kernel);
        $tester = new CommandTester((new Application(self::$kernel))->find('app:endgame:fill-pool'));
        $tester->execute($options);

        return $tester;
    }
}
