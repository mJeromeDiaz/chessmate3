<?php

declare(strict_types=1);

namespace App\Tests\Unit\Chess\Pgn;

use App\Chess\Pgn\Game;
use App\Chess\Pgn\Node;
use App\Chess\Pgn\Parser;
use App\Chess\Pgn\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WriterTest extends TestCase
{
    private const FIXTURES = __DIR__.'/../../../Fixtures/Chess/';

    public function testExportFormat(): void
    {
        $games = (new Parser())->parse((string) file_get_contents(self::FIXTURES.'openbook-white.pgn'));

        self::assertSame(<<<'PGN'
            [Event "OpenBook white repertoire"]
            [Orientation "white"]

            1. d4 d5 2. Nc3 Nf6 3. Bf4 Nc6 (3... e6 4. e3 c5 5. Nb5 Qa5+ 6. b4 Qxb4+ 7. c3
            Qa5 8. Bc7 b6 9. Nd6+ Bxd6 10. Bxd6) 4. e3 Bf5 5. f3 e6 6. g4 Bg6 7. h4 h6 (7...
            h5 8. g5 Nd7 9. Bd3 Bxd3 10. Qxd3 Bb4 11. Ne2 Qe7 12. g6) 8. Bd3 *

            PGN, (new Writer())->write($games[0]));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fixtures(): iterable
    {
        yield 'OpenBook repertoire' => ['openbook-white.pgn'];
        yield 'study export' => ['study-export.pgn'];
    }

    #[DataProvider('fixtures')]
    public function testParsingTheOutputGivesTheSameGames(string $fixture): void
    {
        $parser = new Parser();
        $original = $parser->parse((string) file_get_contents(self::FIXTURES.$fixture));

        $written = (new Writer())->writeAll($original);
        $reparsed = $parser->parse($written);

        self::assertSame(array_map(self::describe(...), $original), array_map(self::describe(...), $reparsed));
        foreach (explode("\n", $written) as $line) {
            self::assertLessThanOrEqual(Writer::LINE_WIDTH, \strlen($line), $line);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function movetexts(): iterable
    {
        yield 'black move numbered after a comment' => ['1. e4 {Best.} e5 *', '1. e4 {Best.} 1... e5 *'];
        yield 'black move numbered after a variation' => ['1. e4 e5 (1... c5) 2. Nf3 Nc6 *', '1. e4 e5 (1... c5) 2. Nf3 Nc6 *'];
        yield 'white variation' => ['1. e4 (1. d4 d5) 1... e5 *', '1. e4 (1. d4 d5) 1... e5 *'];
        yield 'starting comment of a variation' => ['1. e4 e5 ({Or} 1... c5) *', '1. e4 e5 ({Or} 1... c5) *'];
        yield 'NAGs' => ['1. e4! e5?! $14 *', '1. e4 $1 e5 $6 $14 *'];
        yield 'result kept' => ['1. e4 e5 1-0', '1. e4 e5 1-0'];
    }

    #[DataProvider('movetexts')]
    public function testMovetext(string $pgn, string $expected): void
    {
        self::assertSame($expected."\n", (new Writer())->write((new Parser())->parse($pgn)[0]));
    }

    public function testMoveNumbersFollowTheStartingPosition(): void
    {
        $game = (new Parser())->parse('[FEN "r1bqkbnr/pppp1ppp/2n5/4p3/2B1P3/5N2/PPPP1PPP/RNBQK2R b KQkq - 3 3"] 3... Be7 4. d4 *')[0];

        self::assertStringEndsWith("\n\n3... Be7 4. d4 *\n", (new Writer())->write($game));
    }

    public function testCommentsCannotCloseEarlyAndTagsAreEscaped(): void
    {
        $game = new Game();
        $game->tags = ['Event' => 'The "Open" \ 2026'];
        $move = new Node('e4');
        $move->comment = 'Not } the end';
        $game->root->children[] = $move;

        $written = (new Writer())->write($game);
        $reparsed = (new Parser())->parse($written)[0];

        self::assertSame("[Event \"The \\\"Open\\\" \\\\ 2026\"]\n\n1. e4 {Not ) the end} *\n", $written);
        self::assertSame('The "Open" \ 2026', $reparsed->tag('Event'));
        self::assertSame('Not ) the end', $reparsed->root->mainChild()?->comment);
    }

    /**
     * @return array{tags: array<string, string>, result: string|null, tree: string, commands: list<list<array{string, string}>>}
     */
    private static function describe(Game $game): array
    {
        $commands = [];
        $stack = [$game->root];
        while ([] !== $stack) {
            $node = array_pop($stack);
            $commands[] = $node->commands;
            array_push($stack, ...$node->children);
        }

        return [
            'tags' => $game->tags,
            'result' => $game->result,
            'tree' => PgnTreeRenderer::render($game->root).' / '.$game->root->startingComment,
            'commands' => $commands,
        ];
    }
}
