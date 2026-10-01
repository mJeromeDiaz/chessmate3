<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Import;

use App\Repertoire\Import\ImportedTree;
use App\Repertoire\Import\ImportRejectedException;
use App\Repertoire\Import\PgnAnalyzer;
use App\Repertoire\Limits;
use PHPUnit\Framework\TestCase;

final class PgnAnalyzerTest extends TestCase
{
    private const INITIAL = 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq -';

    public function testTheOpenBookRepertoireMergesIntoOneTree(): void
    {
        $tree = (new PgnAnalyzer(new Limits()))->analyze((string) file_get_contents(__DIR__.'/../../../Fixtures/Chess/openbook-white.pgn'));

        self::assertSame(1, $tree->games);
        self::assertSame('white', $tree->color);
        self::assertSame('OpenBook white repertoire', $tree->name);
        self::assertSame([], $tree->warnings);
        // 15 plies of the main line, 14 of 3...e6, 10 of 7...h5.
        self::assertCount(15 + 14 + 10, $tree->edges);
        self::assertCount(15 + 14 + 10 + 1, $tree->positions);
        self::assertSame(['d2d4', 'd7d5', 'b1c3', 'g8f6', 'c1f4', 'b8c6', 'e7e6'], \array_slice(array_column($tree->edges, 'uci'), 0, 7), 'main line first, then the variation of the same position');
    }

    public function testGamesMergeTranspositionsAndKeepTheFirstAnnotations(): void
    {
        $tree = $this->analyze(<<<'PGN'
            [Event "?"]
            1. d4 {Queen's pawn} d5 2. c4 e6 3. Nc3 Nf6 *

            [Event "Second"]
            1. d4 $1 Nf6 2. c4 e6 3. Nc3 d5 {Transposes.} *
            PGN);

        self::assertNull($tree->name);
        self::assertSame(2, $tree->games);
        $d4 = $tree->edges[0];
        self::assertSame(['d2d4', "Queen's pawn", [1]], [$d4['uci'], $d4['comment'], $d4['nags']], 'comment kept, empty NAGs filled by the second game');
        self::assertCount(1, array_filter($tree->edges, static fn (array $e): bool => 'd2d4' === $e['uci']));
        // 3...d5 of the second game reaches the position of 3...Nf6 of the first one.
        $transposing = array_values(array_filter($tree->edges, static fn (array $e): bool => 'Transposes.' === $e['comment']))[0];
        self::assertSame($tree->edges[5]['to'], $transposing['to']);
        self::assertCount(6 + 5, $tree->edges);
        self::assertCount(1 + 6 + 4, $tree->positions);
    }

    public function testAnIllegalMoveEndsItsLineWithAWarning(): void
    {
        $tree = $this->analyze('1. e4 e5 2. Nf3 (2. Ke3 Nc6) 2... Nc6 3. Bb7 a6 *');

        self::assertSame(['e2e4', 'e7e5', 'g1f3', 'b8c6'], array_column($tree->edges, 'uci'));
        self::assertSame([
            ['type' => ImportedTree::ILLEGAL_MOVE, 'game' => 1, 'move' => '2.Ke3'],
            ['type' => ImportedTree::ILLEGAL_MOVE, 'game' => 1, 'move' => '3.Bb7'],
        ], $tree->warnings);
    }

    public function testAMoveBackToItsOwnLineIsRefused(): void
    {
        $tree = $this->analyze('1. Nf3 Nf6 2. Ng1 Ng8 3. e4 *');

        self::assertSame(['Nf3', 'Nf6', 'Ng1'], array_column($tree->edges, 'san'));
        self::assertSame([['type' => ImportedTree::REPEATED_POSITION, 'game' => 1, 'move' => '2...Ng8']], $tree->warnings);
    }

    public function testLinesAreCutAtTheDepthLimit(): void
    {
        $tree = (new PgnAnalyzer(new Limits(maxDepth: 3)))->analyze('1. e4 e5 2. Nf3 Nc6 (2... d6 3. d4) 3. Bb5 *');

        self::assertSame(['e4', 'e5', 'Nf3'], array_column($tree->edges, 'san'));
        self::assertSame([['type' => ImportedTree::TOO_DEEP, 'game' => 1, 'move' => '2...Nc6']], $tree->warnings);
    }

    public function testAChapterFromAFenIsKeptWithItsStart(): void
    {
        $tree = $this->analyze((string) file_get_contents(__DIR__.'/../../../Fixtures/Chess/study-export.pgn'));

        self::assertSame('white', $tree->color);
        self::assertSame('Italian', $tree->name);
        self::assertSame([['game' => 2, 'fen' => 'r1bqkbnr/pppp1ppp/2n5/4p3/2B1P3/5N2/PPPP1PPP/RNBQK2R b KQkq -']], $tree->starts);
        $be7 = array_values(array_filter($tree->edges, static fn (array $e): bool => 'Be7' === $e['san']))[0];
        self::assertSame(2, $be7['game']);
        self::assertSame($tree->starts[0]['fen'], $be7['from'], 'the chapter starts where chapter 1 went (2...Nc6 3.Bc4)');
        $d4 = array_values(array_filter($tree->edges, static fn (array $e): bool => 'The main idea: fast development.' === $e['comment']));
        self::assertSame('e4', $d4[0]['san'] ?? null, 'the game comment stays with the game; a comment before a move goes with it');
    }

    public function testCommentsAndNagsAreMadeAcceptable(): void
    {
        $long = str_repeat('a', 2100);
        $tree = $this->analyze("1. e4 \$1 \$2 \$14 \$15 \$16 \$17 \$18 {{$long}} *");

        self::assertSame([1, 14, 15, 16], $tree->edges[0]['nags']);
        self::assertSame(2000, mb_strlen((string) $tree->edges[0]['comment']));
        self::assertSame([['type' => ImportedTree::COMMENT_TRUNCATED, 'game' => 1, 'move' => '1.e4']], $tree->warnings);
    }

    public function testTheTreeSurvivesItsJsonForm(): void
    {
        $tree = $this->analyze('1. e4 e5 (1... c5) 2. Nf3 *');
        $copy = ImportedTree::fromJson($tree->toJson());
        $copy->addEdge($tree->edges[0]['from'], $tree->edges[0]['to'], 'b', 'e2e4', 'e4', 'late', [3], 2);

        self::assertSame($tree->toArray()['edges'][1], $copy->toArray()['edges'][1]);
        self::assertCount(4, $copy->edges, 'the known move is not added twice');
        self::assertSame('late', $copy->edges[0]['comment']);
        self::assertEquals($tree, ImportedTree::fromJson($tree->toJson()));

        $this->expectException(\UnexpectedValueException::class);
        ImportedTree::fromJson('{"positions": {}, "edges": [{"from": 1}]}');
    }

    /**
     * @return iterable<string, array{string, string, Limits}>
     */
    public static function rejected(): iterable
    {
        yield 'too large' => [str_repeat(' ', 101).'1. e4 *', ImportRejectedException::TOO_LARGE, new Limits(maxImportBytes: 100)];
        yield 'too many games' => ["1. e4 *\n\n1. d4 *\n\n1. c4 *", ImportRejectedException::TOO_MANY_GAMES, new Limits(maxImportGames: 2)];
        yield 'too many positions' => ['1. e4 e5 2. Nf3 *', ImportRejectedException::TOO_MANY_POSITIONS, new Limits(maxPositions: 3)];
        yield 'syntax' => ["1. e4 (e5\n2. Nf3 *", ImportRejectedException::SYNTAX, new Limits()];
        yield 'nothing legal' => ['1. e5 *', ImportRejectedException::EMPTY, new Limits()];
        yield 'empty' => ['', ImportRejectedException::EMPTY, new Limits()];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('rejected')]
    public function testImportsBeyondTheLimitsAreRejected(string $pgn, string $reason, Limits $limits): void
    {
        try {
            (new PgnAnalyzer($limits))->analyze($pgn);
            self::fail('Rejected expected.');
        } catch (ImportRejectedException $e) {
            self::assertSame($reason, $e->reason);
        }
    }

    private function analyze(string $pgn): ImportedTree
    {
        $tree = (new PgnAnalyzer(new Limits()))->analyze($pgn);
        self::assertArrayHasKey(self::INITIAL, $tree->positions);

        return $tree;
    }
}
