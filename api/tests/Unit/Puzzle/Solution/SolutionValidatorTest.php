<?php

declare(strict_types=1);

namespace App\Tests\Unit\Puzzle\Solution;

use App\DataFixtures\Puzzle\SamplePuzzles;
use App\Entity\Catalog\Puzzle;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Puzzle\Solution\SolutionValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SolutionValidatorTest extends TestCase
{
    /** Lichess puzzle K69di, mate in 2 (FEN before the opponent's move, as in the CSV). */
    private const MATE_IN_2 = ['8/8/8/6pp/5r1k/5p1r/5K2/4Q3 b - - 0 62', 'g5g4 e1e7 f4f6 e7f6'];
    /** Back-rank mate in one: Ra8# is the solution, Rb8# mates too. */
    private const MATE_IN_1 = ['6k1/5ppp/2n5/8/8/8/8/RR4K1 b - - 0 1', 'c6d4 a1a8'];
    private const PROMOTION = ['8/P6k/8/7p/8/8/8/6K1 b - - 0 1', 'h5h4 a7a8q'];

    private SolutionValidator $validator;

    protected function setUp(): void
    {
        $this->validator = new SolutionValidator();
    }

    public function testTheExactSolutionIsACleanCompletion(): void
    {
        $result = $this->validator->replay(self::puzzle(self::MATE_IN_2), ['e1e7', 'e7f6']);

        self::assertTrue($result->completed);
        self::assertTrue($result->isClean());
        self::assertSame(2, $result->progress);
    }

    public function testAWrongMoveIsAMistakeAndIsTakenBack(): void
    {
        $result = $this->validator->replay(self::puzzle(self::MATE_IN_2), ['e1e2', 'e1e7', 'e7f6']);

        self::assertTrue($result->completed);
        self::assertSame(1, $result->mistakes);
        self::assertFalse($result->isClean());
    }

    public function testAPartialLogIsNotACompletion(): void
    {
        $result = $this->validator->replay(self::puzzle(self::MATE_IN_2), ['e1e7']);

        self::assertFalse($result->completed);
        self::assertSame(1, $result->progress);
        self::assertFalse($this->validator->replay(self::puzzle(self::MATE_IN_2), [])->completed);
    }

    public function testAnAlternativeMateInOneIsAccepted(): void
    {
        $solution = $this->validator->replay(self::puzzle(self::MATE_IN_1), ['a1a8']);
        $alternative = $this->validator->replay(self::puzzle(self::MATE_IN_1), ['b1b8']);

        self::assertTrue($solution->isClean());
        self::assertTrue($alternative->isClean());
    }

    public function testTheRightPromotionPieceIsRequired(): void
    {
        $knight = $this->validator->replay(self::puzzle(self::PROMOTION), ['a7a8n', 'a7a8q']);

        self::assertTrue($knight->completed);
        self::assertSame(1, $knight->mistakes);
        self::assertTrue($this->validator->replay(self::puzzle(self::PROMOTION), ['a7a8q'])->isClean());
    }

    /**
     * @return iterable<string, array{list<string>}>
     */
    public static function impossibleLogs(): iterable
    {
        yield 'illegal move' => [['e1e1']];
        yield 'not UCI' => [['Qe7']];
        yield 'promotion letter on a non-promotion move' => [['e1e7q']];
        yield 'moves after the end' => [['e1e7', 'e7f6', 'f6f7']];
        yield 'opponent piece moved' => [['h4h5']];
    }

    /**
     * @param list<string> $moves
     */
    #[DataProvider('impossibleLogs')]
    public function testAnImpossibleLogIsRejected(array $moves): void
    {
        $this->expectException(InvalidSubmissionException::class);

        $this->validator->replay(self::puzzle(self::MATE_IN_2), $moves);
    }

    public function testAMissingPromotionPieceIsRejected(): void
    {
        $this->expectException(InvalidSubmissionException::class);

        $this->validator->replay(self::puzzle(self::PROMOTION), ['a7a8']);
    }

    public function testAnOverlongLogIsRejected(): void
    {
        $this->expectException(InvalidSubmissionException::class);

        $this->validator->replay(self::puzzle(self::MATE_IN_2), array_fill(0, SolutionValidator::MAX_LOGGED_MOVES + 1, 'e1e2'));
    }

    /**
     * Every sample puzzle (real Lichess data) is solved cleanly by its own solution, and any
     * other checkmating first move of a mate in one is accepted too.
     */
    public function testEverySamplePuzzleIsSolvableAsImported(): void
    {
        $puzzles = SamplePuzzles::create();
        self::assertGreaterThanOrEqual(45, \count($puzzles));

        foreach ($puzzles as $puzzle) {
            $moves = $puzzle->getMoveList();
            $playerMoves = array_values(array_filter($moves, static fn (int $i): bool => 1 === $i % 2, \ARRAY_FILTER_USE_KEY));

            self::assertTrue($this->validator->replay($puzzle, $playerMoves)->isClean(), $puzzle->getLichessId());
        }
    }

    /**
     * @param array{string, string} $data
     */
    private static function puzzle(array $data): Puzzle
    {
        return new Puzzle('test1', $data[0], $data[1], 1500, 80, 90, 1000, ['mate'], 'https://lichess.org/test');
    }
}
