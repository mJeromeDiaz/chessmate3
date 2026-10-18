<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Auto;

use App\Chess\Rules;
use App\Entity\User;
use App\Enum\Repertoire\Color;
use App\Repertoire\Auto\AutoBuildService;
use App\Repertoire\Auto\Exploration;
use App\Repertoire\Auto\ExplorationRunner;
use App\Repertoire\Auto\GameRecord;
use App\Repertoire\Auto\ReplyRule;
use App\Repertoire\Auto\ReplySource;
use App\Repertoire\Auto\Tree;
use App\Repertoire\Auto\TreeBuilder;
use App\Repertoire\Limits;
use PHPUnit\Framework\TestCase;

/**
 * Repertoire from my games with the Lichess explorer (docs/REPERTOIRE.md § 12 bis): the replies kept,
 * the user's reference move, the pending positions, the short lines, the frontier, the call cap.
 *
 * @phpstan-import-type Replies from ReplyRule
 */
final class ExplorationTest extends TestCase
{
    public function testAReplyNeedsItsShareAndItsGames(): void
    {
        $rule = ReplyRule::fromLimits(new Limits());
        $replies = self::replies(2000, [['e7e5', 'e5', 120], ['c7c5', 'c5', 99], ['e7e6', 'e6', 100]]);
        // Of 2000 games: 120 kept; 99 (4.95 %) refused; 100 (5 %) kept.
        self::assertSame(['e7e5', 'e7e6'], array_column($rule->popular($replies), 'uci'));
        $shares = ['total' => 1667, 'moves' => [self::move('c7c5', 'c5', 99, 5.0), self::move('e7e6', 'e6', 100, 6.0)]];
        self::assertSame(['e7e6'], array_column($rule->popular($shares), 'uci'), '5 % and 99 games refused, 6 % and 100 games kept');
    }

    public function testAtMostFourReplies(): void
    {
        $replies = self::replies(10000, [['e7e5', 'e5', 3000], ['c7c5', 'c5', 2500], ['e7e6', 'e6', 1500], ['c7c6', 'c6', 1000], ['d7d6', 'd6', 800], ['g7g6', 'g6', 700]]);

        self::assertSame(['e7e5', 'c7c5', 'e7e6', 'c7c6'], array_column(ReplyRule::fromLimits(new Limits())->popular($replies), 'uci'));
        self::assertCount(2, (new ReplyRule(5.0, 100, 2))->popular($replies));
    }

    public function testTheReferenceMoveIsTheMostPlayedFromTwoGames(): void
    {
        $games = [
            self::game('e2e4 e7e5 g1f3'),
            self::game('e2e4 e7e5 g1f3'),
            self::game('d2d4 d7d5'),
            // After 1.e4 c5 the user played 2.Nf3 once and 2.c3 once: no move of theirs.
            self::game('e2e4 c7c5 g1f3'),
            self::game('e2e4 c7c5 c2c3'),
        ];
        $tree = self::builder()->build($games, Color::White);

        self::assertSame('e2e4', $tree->nodes['e2e4']['path'], '1.e4 three times, 1.d4 once');
        self::assertArrayNotHasKey('d2d4', $tree->nodes);
        self::assertArrayHasKey('e2e4 e7e5 g1f3', $tree->nodes);
        self::assertArrayNotHasKey('e2e4 c7c5', $tree->nodes, 'a reply left without a move played twice is no line');
        self::assertSame([], self::builder()->build([self::game('e2e4 e7e5')], Color::White)->nodes, 'a first move played once: nothing');
    }

    public function testPopularRepliesTheUserDidNotAnswerArePending(): void
    {
        $games = [
            self::game('e2e4 e7e5 g1f3'),
            self::game('e2e4 e7e5 g1f3'),
            // 1...c5 met once: the user's 2.Nf3 there is played once only.
            self::game('e2e4 c7c5 g1f3'),
        ];
        $afterE4 = self::fen('e2e4');
        $explorer = [$afterE4 => self::replies(10000, [['e7e5', 'e5', 4000], ['c7c5', 'c5', 3000], ['e7e6', 'e6', 1500], ['a7a6', 'a6', 90]])];
        $tree = self::builder()->build($games, Color::White, explorer: $explorer);

        self::assertSame(['e2e4 c7c5', 'e2e4 e7e6'], array_column($tree->pending, 'path'), '1...a6 is under the thresholds');
        [$c5, $e6] = $tree->pending;
        self::assertSame([Tree::NO_USER_MOVE, ['e4', 'c5'], 3000, 30.0, self::fen('e2e4 c7c5')], [$c5['reason'], $c5['sans'], $c5['games'], $c5['share'], $c5['fen']]);
        self::assertSame([Tree::REPLY_UNCOVERED, ['e4', 'e6']], [$e6['reason'], $e6['sans']]);
        self::assertSame(50, $e6['score'], 'half wins for White, half losses in the simulated answer');
        self::assertArrayNotHasKey('e2e4 c7c5', $tree->nodes, 'never in the tree: the user has not answered it');
        self::assertSame([self::fen('e2e4 e7e5 g1f3')], array_column($tree->frontier, 'fen'), 'the next position to ask');
    }

    public function testAShortLineTellsWhereItStops(): void
    {
        $games = [self::game('e2e4 e7e5 g1f3'), self::game('e2e4 e7e5 g1f3')];
        $leaf = self::fen('e2e4 e7e5 g1f3');

        $unexplored = self::builder()->build($games, Color::White)->lines[0];
        self::assertSame([3, true, 'unexplored'], [$unexplored['depth'], $unexplored['short'], $unexplored['stop']['kind']]);

        $cut = self::builder()->build($games, Color::White, explorer: [$leaf => self::replies(1000, [['b8c6', 'Nc6', 700], ['d7d6', 'd6', 200]])])->lines[0];
        self::assertSame(['reply_uncovered', 'Nc6', 'e2e4 e7e5 g1f3 b8c6'], [$cut['stop']['kind'], $cut['stop']['san'], $cut['stop']['path']], 'the most popular reply that cuts it');

        $none = self::builder()->build($games, Color::White, explorer: [$leaf => self::replies(1000, [['b8c6', 'Nc6', 50]])])->lines[0];
        self::assertSame('no_popular_reply', $none['stop']['kind']);

        $long = (new TreeBuilder(new Limits(autoShortLinePlies: 3)))->build($games, Color::White)->lines[0];
        self::assertFalse($long['short'], 'from the threshold on, a line is kept without asking');
    }

    public function testABranchUnderTheThresholdIsNotDeveloped(): void
    {
        $games = [
            self::game('e2e4 e7e5 g1f3'),
            self::game('e2e4 e7e5 g1f3'),
            // 1...a6 met once: neither in the tree nor asked about.
            self::game('e2e4 a7a6 d2d4 b7b5 c2c4'),
        ];
        $tree = self::builder()->build($games, Color::White);

        self::assertArrayNotHasKey('e2e4 a7a6', $tree->nodes);
        self::assertSame([self::fen('e2e4'), self::fen('e2e4 e7e5 g1f3')], array_column($tree->frontier, 'fen'), 'shallowest first, nothing behind 1...a6');
    }

    public function testAShortLineSetAsideIsPendingWhereItLeavesTheOthers(): void
    {
        $pending = AutoBuildService::shortLinePending('e2e4 c7c5', Color::White);
        self::assertNotNull($pending);
        self::assertSame([Tree::SHORT_LINE, ['e4', 'c5'], self::fen('e2e4 c7c5'), null], [$pending['reason'], $pending['sans'], $pending['fen'], $pending['games']]);

        $only = AutoBuildService::shortLinePending('e2e4', Color::White);
        self::assertNotNull($only);
        self::assertSame([[], Rules::initial()->normalizedFen()], [$only['sans'], $only['fen']], 'the only line, cut at the user\'s first move: the initial position');
    }

    public function testTheCapStopsTheExplorationWhichThenGoesOnWhereItStopped(): void
    {
        $source = new FakeReplySource();
        $limits = new Limits(autoMaxExplorerCalls: 3, autoExplorerCallsPerRequest: 10);
        $runner = new ExplorationRunner($source, $limits);
        $exploration = Exploration::fromStored(null, $limits);
        $frontier = static fn (): array => ['a', 'b', 'c', 'd', 'e'];

        self::assertSame(3, $runner->run($exploration, null, $frontier)['calls']);
        self::assertTrue($exploration->capped);
        self::assertSame(0, $runner->run($exploration, null, $frontier)['calls'], 'capped: nothing more until a resume');

        $exploration = Exploration::fromStored($exploration->toArray(), $limits);
        $exploration->resume($limits->autoMaxExplorerCalls);
        self::assertSame(2, $runner->run($exploration, null, $frontier)['calls']);
        self::assertSame(['a', 'b', 'c', 'd', 'e'], $source->asked, 'no position asked twice: the exploration went on');
        self::assertSame([5, 6, false], [$exploration->calls, $exploration->budget, $exploration->capped]);
    }

    public function testOnlyRealCallsCountAndARequestMakesAFew(): void
    {
        $source = new FakeReplySource(free: ['a', 'b']);
        $limits = new Limits(autoMaxExplorerCalls: 300, autoExplorerCallsPerRequest: 2);
        $runner = new ExplorationRunner($source, $limits);
        $exploration = Exploration::fromStored(null, $limits);

        self::assertSame(2, $runner->run($exploration, null, static fn (): array => ['a', 'b', 'c', 'd', 'e'])['calls']);
        self::assertSame(['a', 'b', 'c', 'd'], $source->asked, 'the table and the cache are free');
        self::assertSame(2, $exploration->calls);
    }

    public function testALineHasItsOwnCalls(): void
    {
        $limits = new Limits(autoMaxExplorerCalls: 2, autoExplorerCallsPerRequest: 10);
        $runner = new ExplorationRunner(new FakeReplySource(), $limits);
        $exploration = Exploration::fromStored(null, $limits);
        $exploration->calls = 2;
        $exploration->capped = true;

        self::assertSame(2, $runner->run($exploration, null, static fn (): array => ['a', 'b', 'c'], 'white:Italian Game|e2e4 e7e5')['calls']);
        self::assertSame([2, ['white:Italian Game|e2e4 e7e5' => 2]], [$exploration->calls, $exploration->lines], 'the analysis\' calls untouched');
    }

    private static function builder(): TreeBuilder
    {
        return new TreeBuilder(new Limits());
    }

    private static function game(string $moves): GameRecord
    {
        $uci = explode(' ', $moves);

        return new GameRecord(uniqid('g', true), Color::White, 1, 1790000000000, 'blitz', null, null, 'Opening', 'Opening', $uci, array_fill(0, \count($uci), null), [], false);
    }

    private static function fen(string $moves): string
    {
        $rules = Rules::initial();
        foreach (explode(' ', $moves) as $uci) {
            $rules->playUci($uci);
        }

        return $rules->normalizedFen();
    }

    /**
     * @param list<array{string, string, int}> $moves UCI, SAN, games
     *
     * @return Replies
     */
    private static function replies(int $total, array $moves): array
    {
        return ['total' => $total, 'moves' => array_map(static fn (array $move): array => self::move($move[0], $move[1], $move[2], round(100 * $move[2] / $total, 1)), $moves)];
    }

    /**
     * @return array{uci: string, san: string, games: int, share: float, white: int, draws: int, black: int}
     */
    private static function move(string $uci, string $san, int $games, float $share): array
    {
        $white = intdiv($games, 2);

        return ['uci' => $uci, 'san' => $san, 'games' => $games, 'share' => $share, 'white' => $white, 'draws' => 0, 'black' => $games - $white];
    }
}

/**
 * Answers every position with no reply; the positions in `free` as the table or the cache would.
 */
final class FakeReplySource implements ReplySource
{
    /** @var list<string> */
    public array $asked = [];

    /**
     * @param list<string> $free
     */
    public function __construct(private readonly array $free = [])
    {
    }

    public function replies(?User $user, string $fen, ?bool &$fetched = null, string $preset = Limits::AUTO_EXPLORER_PRESET): array
    {
        $this->asked[] = $fen;
        $fetched = !\in_array($fen, $this->free, true);

        return ['total' => 0, 'moves' => []];
    }
}
