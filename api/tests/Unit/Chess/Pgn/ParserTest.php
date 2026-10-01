<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chess\Pgn;

use App\Chess\Pgn\Game;
use App\Chess\Pgn\Parser;
use App\Chess\Pgn\PgnSyntaxException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/Chess/';

    public function testAStudyExportWithNestedVariationsCommentsAndNags(): void
    {
        $games = $this->parse((string) file_get_contents(self::FIXTURES.'study-export.pgn'));

        self::assertCount(2, $games);
        [$first, $second] = $games;

        self::assertSame('Italian: chapter 1', $first->tag('Event'));
        self::assertSame('https://lichess.org/study/abcdEFGH/ijklMNOP', $first->tag('Site'));
        self::assertSame('white', $first->tag('Orientation'));
        self::assertSame('*', $first->result);
        self::assertNull($first->startingFen());
        self::assertSame(
            '<The main idea: fast development.> e4 e5 Nf3 {Attacks e5.} Nc6 Bc4 Bc5'
            .' (Nf6$5 {The Two Knights.} Ng5 (d3 {Quiet.}) d5 exd5 Na5 (Nxd5$6 Nxf7$5 (d4) Kxf7) Bb5+)'
            .' c3$1 Nf6 d4 exd4 cxd4 Bb4+ Nc3',
            PgnTreeRenderer::render($first->root),
        );

        $e4 = $first->root->mainChild();
        self::assertNotNull($e4);
        self::assertSame([['csl', 'Ge4']], $e4->commands);
        $nf3 = $e4->mainChild()?->mainChild();
        self::assertNotNull($nf3);
        self::assertSame([['cal', 'Gb8c6']], $nf3->commands);
        self::assertSame(9, $nf3->line);

        self::assertSame('r1bqkbnr/pppp1ppp/2n5/4p3/2B1P3/5N2/PPPP1PPP/RNBQK2R b KQkq - 3 3', $second->startingFen());
        self::assertSame('Be7 d4 d6 (exd4 Nxd4) d5', PgnTreeRenderer::render($second->root));
    }

    public function testTheOpenBookRepertoireHasThreeLines(): void
    {
        $games = $this->parse((string) file_get_contents(self::FIXTURES.'openbook-white.pgn'));

        self::assertCount(1, $games);
        self::assertSame('white', $games[0]->tag('Orientation'));
        self::assertSame([
            explode(' ', 'd4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4 h6 Bd3'),
            explode(' ', 'd4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4 h5 g5 Nd7 Bd3 Bxd3 Qxd3 Bb4 Ne2 Qe7 g6'),
            explode(' ', 'd4 d5 Nc3 Nf6 Bf4 e6 e3 c5 Nb5 Qa5+ b4 Qxb4+ c3 Qa5 Bc7 b6 Nd6+ Bxd6 Bxd6'),
        ], PgnTreeRenderer::lines($games[0]->root));
        self::assertCount(3, $games[0]->root->leaves());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function movetexts(): iterable
    {
        yield 'move numbers in every form' => ['1.e4 e5 2.Nf3 2...Nc6 3. Bb5 3… a6 4 Ba4 *', 'e4 e5 Nf3 Nc6 Bb5 a6 Ba4'];
        yield 'suffix annotations and NAGs' => ['1. e4! e5? 2. Nf3!! Nc6?? 3. Bb5!? a6?! 4. Ba4 $14 $32 *', 'e4$1 e5$2 Nf3$3 Nc6$4 Bb5$5 a6$6 Ba4$14$32'];
        yield 'line comment and escape line' => ["% generated\n1. e4 ; to the end of the line\ne5 *", 'e4 {to the end of the line} e5'];
        yield 'moves kept as written' => ['1. e4 e5 2. O-O 0-0-0 3. exd6e.p. *', 'e4 e5 O-O 0-0-0 exd6e.p.'];
        yield 'several variations of one move' => ['1. e4 (1. d4) (1. c4 c5) e5 *', 'e4 (d4) (c4 c5) e5'];
        yield 'comment after a variation goes to the move before it' => ['1. e4 (1. d4) { after } e5 *', 'e4 {after} (d4) e5'];
        yield 'comment at the start of a variation' => ['1. e4 e5 ( { Sicilian } 1... c5 ) *', 'e4 e5 (<Sicilian> c5)'];
        yield 'comments joined and whitespace collapsed' => ["1. e4 { one\n  two } { three } *", 'e4 {one two three}'];
        yield 'variation of a variation' => ['1. e4 e5 (1... c5 2. Nf3 (2. c3 d5 (2... Nf6)) 2... d6) 2. Nf3 *', 'e4 e5 (c5 Nf3 (c3 d5 (Nf6)) d6) Nf3'];
        yield 'no result' => ['1. d4 d5', 'd4 d5'];
    }

    #[DataProvider('movetexts')]
    public function testMovetext(string $pgn, string $expected): void
    {
        $games = $this->parse($pgn);

        self::assertCount(1, $games);
        self::assertSame($expected, PgnTreeRenderer::render($games[0]->root));
    }

    public function testGamesWithoutTagsAreSeparatedByTheirResult(): void
    {
        $games = $this->parse("1. e4 e5 1-0\n1. d4 d5 0-1\n1. c4 1/2-1/2");

        self::assertSame(['1-0', '0-1', '1/2-1/2'], array_map(static fn (Game $game): ?string => $game->result, $games));
        self::assertSame('c4', PgnTreeRenderer::render($games[2]->root));
    }

    public function testGamesAreSeparatedByTheirTags(): void
    {
        $games = $this->parse("[Event \"A\"]\n1. e4\n\n[Event \"B\"]\n1. d4 *");

        self::assertSame(['A', 'B'], array_map(static fn (Game $game): ?string => $game->tag('Event'), $games));
        self::assertNull($games[0]->result);
    }

    public function testTagValuesAreUnescaped(): void
    {
        $games = $this->parse('[White "Nimzo \"The Great\" \\\\ Aron"] 1. e4 *');

        self::assertSame('Nimzo "The Great" \ Aron', $games[0]->tag('White'));
    }

    public function testAGameCommentWithoutMoves(): void
    {
        $games = $this->parse('[Event "Empty"] { Nothing yet. [%csl Rd4] } *');

        self::assertSame('Nothing yet.', $games[0]->root->startingComment);
        self::assertSame([['csl', 'Rd4']], $games[0]->root->commands);
        self::assertSame([], $games[0]->root->children);
    }

    public function testWindows1252TextIsConverted(): void
    {
        $games = $this->parse("1. e4 { D\xE9veloppement rapide. } *");

        self::assertSame('Développement rapide.', $games[0]->root->mainChild()?->comment);
    }

    public function testAnEmptyTextHasNoGame(): void
    {
        self::assertSame([], $this->parse("  \n "));
    }

    /**
     * @return iterable<string, array{string, int}>
     */
    public static function invalidTexts(): iterable
    {
        yield 'unclosed comment' => ["1. e4\n{ never closed", 2];
        yield 'unclosed variation' => ["1. e4 (1. d4\n*", 2];
        yield 'unclosed variation at the end' => ["1. e4 (1. d4 d5\n", 2];
        yield 'unexpected closing parenthesis' => ['1. e4 ) e5 *', 1];
        yield 'variation before any move' => ['( 1. e4 ) *', 1];
        yield 'malformed tag' => ["[Event \"A\"]\n[White Kasparov]\n1. e4 *", 2];
        yield 'unexpected character' => ["1. e4\n\n2. @ *", 3];
        yield 'NAG out of range' => ['1. e4 $256 *', 1];
        yield 'variations nested too deeply' => ['1. e4 e5 '.str_repeat('(1... c5 ', 33).str_repeat(')', 33).' *', 1];
    }

    #[DataProvider('invalidTexts')]
    public function testInvalidTextIsRejectedWithItsLine(string $pgn, int $line): void
    {
        try {
            $this->parse($pgn);
            self::fail('No exception.');
        } catch (PgnSyntaxException $e) {
            self::assertSame($line, $e->pgnLine, $e->getMessage());
        }
    }

    public function testThirtyTwoNestedVariationsAreAccepted(): void
    {
        $games = $this->parse('1. e4 e5 '.str_repeat('(1... c5 ', 32).str_repeat(')', 32).' *');

        self::assertCount(33, $games[0]->root->leaves());
    }

    /**
     * @return list<Game>
     */
    private function parse(string $pgn): array
    {
        return (new Parser())->parse($pgn);
    }
}
