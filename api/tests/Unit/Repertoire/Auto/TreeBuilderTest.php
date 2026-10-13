<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Auto;

use App\Enum\Repertoire\Color;
use App\Repertoire\Auto\GameRecord;
use App\Repertoire\Auto\Synthesis;
use App\Repertoire\Auto\Tree;
use App\Repertoire\Auto\TreeBuilder;
use App\Repertoire\Limits;
use PHPUnit\Framework\TestCase;

final class TreeBuilderTest extends TestCase
{
    private const BC4 = 'e2e4 e7e5 g1f3 b8c6 f1c4';
    private const NC6 = 'e2e4 e7e5 g1f3 b8c6';

    public function testMostPlayedMovesAndRepliesPlayedOftenEnough(): void
    {
        $tree = (new TreeBuilder(new Limits()))->build(self::whiteGames(), Color::White);

        self::assertSame(1, $tree->trunk, '1.e4, then 1...e5 and 1...c5');
        self::assertSame([self::BC4, 'e2e4 c7c5 g1f3 d7d6 d2d4'], array_column($tree->lines, 'path'));
        self::assertSame(['e2e4 e7e5', 'e2e4 c7c5'], array_column($tree->lines, 'cut'));
        self::assertArrayNotHasKey('e2e4 e7e6', $tree->nodes, '1...e6 played once only');
        self::assertArrayNotHasKey('d2d4', $tree->nodes, '1.d4 is not the most played');
        self::assertSame([], $tree->nodes['e2e4']['alternatives'], '1.d4 played once: not offered');

        $italian = $tree->lines[0];
        self::assertSame('Giuoco Piano', $italian['name']);
        self::assertSame([4, 3, 0, 1, 2, 1], [$italian['games'], $italian['wins'], $italian['draws'], $italian['losses'], $italian['analysed'], $italian['errored']]);
        self::assertSame(['e2e4', 'e2e4 e7e5', 'e2e4 e7e5 g1f3', self::NC6, self::BC4], $italian['nodes']);

        $bc4 = $tree->nodes[self::BC4];
        self::assertSame(['Bc4', 5, true, false], [$bc4['san'], $bc4['depth'], $bc4['user'], $bc4['suggested']]);
        self::assertSame(600, $bc4['think'], '(600 + 1000 + 200) / 3, a game without clocks left out');
        self::assertSame([2, 1], [$bc4['moveAnalysed'], $bc4['moveErrors']]);
        self::assertSame(['uci' => 'd2d4', 'san' => 'd4'], $bc4['suggestion']);
        self::assertSame([['uci' => 'f1b5', 'san' => 'Bb5', 'games' => 2, 'wins' => 0, 'draws' => 2, 'losses' => 0]], $bc4['alternatives']);
        self::assertFalse($tree->nodes[self::NC6]['user']);
        self::assertSame('Sicilian Defense', $tree->lines[1]['name'], 'the family name when no variation is given');
    }

    public function testAnOpponentReplyPlayedTwiceIsKept(): void
    {
        $games = [...self::whiteGames(), self::game(Color::White, 1, 'e2e4 e7e6 d2d4 d7d5', 'French Defense')];
        $tree = (new TreeBuilder(new Limits()))->build($games, Color::White);

        self::assertArrayHasKey('e2e4 e7e6', $tree->nodes, '1...e6 played twice');
        self::assertSame('e2e4 e7e6 d2d4', $tree->nodes['e2e4 e7e6 d2d4']['path'], 'and the user\'s move after it');
        self::assertArrayNotHasKey('e2e4 e7e6 d2d4 d7d5', $tree->nodes, '3...d5 played once only');
        self::assertArrayNotHasKey('e2e4 e7e6', (new TreeBuilder(new Limits(autoMinGames: 3)))->build($games, Color::White)->nodes);
    }

    public function testTheUserChoosesAnotherMoveOfTheirs(): void
    {
        $tree = (new TreeBuilder(new Limits()))->build(self::whiteGames(), Color::White, [self::NC6 => 'f1b5']);

        $bb5 = $tree->nodes[self::NC6.' f1b5'];
        self::assertSame(['Bb5', 2, false], [$bb5['san'], $bb5['games'], $bb5['suggested']]);
        self::assertSame([['uci' => 'f1c4', 'san' => 'Bc4', 'games' => 4, 'wins' => 3, 'draws' => 0, 'losses' => 1]], $bb5['alternatives']);
        self::assertArrayNotHasKey(self::BC4, $tree->nodes);
        self::assertSame('Ruy Lopez', $tree->lines[0]['name']);
    }

    public function testTheUserChoosesLichessBestMoveAndTheLineStopsThere(): void
    {
        $tree = (new TreeBuilder(new Limits()))->build(self::whiteGames(), Color::White, [self::NC6 => 'd2d4']);

        $d4 = $tree->nodes[self::NC6.' d2d4'];
        self::assertSame(['d4', 0, true, null], [$d4['san'], $d4['games'], $d4['suggested'], $d4['think']]);
        self::assertSame(['f1c4', 'f1b5'], array_column($d4['alternatives'], 'uci'), 'the moves they played, most played first');
        $line = $tree->lines[0];
        self::assertSame(self::NC6.' d2d4', $line['path']);
        self::assertSame(6, $line['games'], 'the games as far as the user played the line');
    }

    public function testAChoiceNeitherPlayedNorSuggestedIsIgnored(): void
    {
        $tree = (new TreeBuilder(new Limits()))->build(self::whiteGames(), Color::White, [self::NC6 => 'a2a3']);

        self::assertArrayHasKey(self::BC4, $tree->nodes);
    }

    public function testARemovedLineGoesWithWhatFollowsIt(): void
    {
        $tree = (new TreeBuilder(new Limits()))->build(self::whiteGames(), Color::White, [], ['e2e4 c7c5']);

        self::assertSame([self::BC4], array_column($tree->lines, 'path'));
        self::assertSame(5, $tree->trunk);
        self::assertSame('e2e4', $tree->lines[0]['cut'], 'the only line left: removing it removes everything');
    }

    public function testWithBlackTheOpponentStarts(): void
    {
        $games = [
            self::game(Color::Black, 1, 'e2e4 c7c5 g1f3 d7d6', 'Sicilian Defense: Modern Variations'),
            self::game(Color::Black, 0, 'e2e4 c7c5 g1f3 d7d6', 'Sicilian Defense: Modern Variations'),
            self::game(Color::Black, -1, 'e2e4 c7c5 g1f3 d7d6', 'Sicilian Defense: Modern Variations'),
        ];
        $tree = (new TreeBuilder(new Limits()))->build($games, Color::Black);

        self::assertSame(['e2e4 c7c5 g1f3 d7d6'], array_column($tree->lines, 'path'));
        self::assertSame([false, true, false, true], array_column(array_values($tree->nodes), 'user'));
        self::assertSame('e2e4', $tree->lines[0]['cut']);
        self::assertSame('Modern Variations', $tree->lines[0]['name']);
    }

    public function testLinesStopAtThePlyLimit(): void
    {
        $tree = (new TreeBuilder(new Limits(autoMaxPlies: 3)))->build(self::whiteGames(), Color::White);

        self::assertSame(['e2e4 e7e5 g1f3', 'e2e4 c7c5 g1f3'], array_column($tree->lines, 'path'));
    }

    public function testNoGamesNoLines(): void
    {
        $tree = (new TreeBuilder(new Limits()))->build([], Color::White);

        self::assertInstanceOf(Tree::class, $tree);
        self::assertSame([[], []], [$tree->nodes, $tree->lines]);
    }

    public function testSynthesisPerFamily(): void
    {
        $synthesis = Synthesis::of([...self::whiteGames(), self::game(Color::Black, 1, 'e2e4 c7c6', 'Caro-Kann Defense')], 3);

        self::assertSame([12, 2, 5, 3, 4], [$synthesis['games'], $synthesis['analysed'], $synthesis['wins'], $synthesis['draws'], $synthesis['losses']]);
        self::assertSame(['white:Italian Game', 'white:Sicilian Defense'], array_column($synthesis['families'], 'key'), 'most played first, families played fewer than 3 times left out');
        self::assertSame(['family' => 'Italian Game', 'color' => 'white', 'games' => 4, 'wins' => 3, 'draws' => 0, 'losses' => 1, 'analysed' => 2], array_diff_key($synthesis['families'][0], ['key' => true]));
    }

    /**
     * 11 games with White: 1.e4 e5 2.Nf3 Nc6 3.Bc4 (4, two analysed, a mistake on 3.Bc4 in one) or
     * 3.Bb5 (2 draws), 1.e4 c5 2.Nf3 d6 3.d4 (3), 1.e4 e6 2.d4 (1), 1.d4 (1).
     *
     * @return list<GameRecord>
     */
    private static function whiteGames(): array
    {
        $think = static fn (?int $cs): array => [null, null, null, null, $cs];

        return [
            self::game(Color::White, 1, self::BC4, 'Italian Game: Giuoco Piano', true, [[4, GameRecord::MISTAKE, 'd2d4']], $think(600)),
            self::game(Color::White, 1, self::BC4, 'Italian Game: Giuoco Piano', true, [[4, GameRecord::INACCURACY, 'c2c3']], $think(1000)),
            self::game(Color::White, 1, self::BC4, 'Italian Game', false, [], $think(null)),
            self::game(Color::White, -1, self::BC4, 'Italian Game: Giuoco Piano', false, [], $think(200)),
            self::game(Color::White, 0, self::NC6.' f1b5', 'Ruy Lopez'),
            self::game(Color::White, 0, self::NC6.' f1b5', 'Ruy Lopez'),
            self::game(Color::White, -1, 'e2e4 c7c5 g1f3 d7d6 d2d4', 'Sicilian Defense'),
            self::game(Color::White, -1, 'e2e4 c7c5 g1f3 d7d6 d2d4', 'Sicilian Defense'),
            self::game(Color::White, 1, 'e2e4 c7c5 g1f3 d7d6 d2d4', 'Sicilian Defense'),
            self::game(Color::White, 0, 'e2e4 e7e6 d2d4', 'French Defense'),
            self::game(Color::White, -1, 'd2d4 d7d5', 'Queen\'s Pawn Game'),
        ];
    }

    /**
     * @param list<array{int, string, string|null}> $judgments
     * @param list<int|null>                        $thinks
     */
    private static function game(Color $color, int $result, string $moves, string $opening, bool $analysed = false, array $judgments = [], array $thinks = []): GameRecord
    {
        $uci = explode(' ', $moves);
        $thinks = array_slice([...$thinks, ...array_fill(0, \count($uci), null)], 0, \count($uci));

        return new GameRecord(uniqid('g', true), $color, $result, 1790000000000, 'blitz', null, null, trim(explode(':', $opening)[0]), $opening, $uci, $thinks, $judgments, $analysed);
    }
}
