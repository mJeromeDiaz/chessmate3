<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Chess\Rules;
use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Repertoire\Auto\OpeningStatsReader;
use App\Security\OAuth\OAuthTokenVault;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Repertoire from my games with the Lichess explorer (docs/REPERTOIRE.md § 12 bis): the precomputed
 * first moves read before the explorer, the exploration of an analysis, its pending positions
 * written with the repertoire and never in it, answered (one undoable change), refused where the
 * position has its move, dismissed and restored. Lichess simulated, never called.
 *
 * @phpstan-import-type ChangeJson from RepertoireWebTestCase
 *
 * @phpstan-type PendingItemJson array{id: string, fen: string, path: list<string>, reason: string, games: int|null, share: float|null, score: int|null, status: string, answerUci: string|null}
 * @phpstan-type PendingListJson array{id: string, open: int, items: list<PendingItemJson>}
 */
final class AutoBuildExploreTest extends RepertoireWebTestCase
{
    private const ITALIAN = 'white:Italian Game';

    /** @var list<string> */
    private array $calls = [];
    /** @var list<MockResponse> */
    private array $games = [];
    /** @var array<string, array<mixed>> normalized FEN => the explorer's body */
    private array $explorer = [];
    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->clock = new MockClock('2026-10-18 10:00:00', 'UTC');
        Clock::set($this->clock);
        self::getContainer()->set('lichess_api.client', new MockHttpClient(function (string $method, string $url): MockResponse {
            return array_shift($this->games) ?? throw new \LogicException('Unexpected call to Lichess: '.$url);
        }));
        self::getContainer()->set('lichess_explorer.client', new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->calls[] = $url;
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $fen = \is_string($query['fen'] ?? null) ? implode(' ', \array_slice(explode(' ', $query['fen']), 0, 4)) : '';

            return new JsonMockResponse($this->explorer[$fen] ?? ['white' => 0, 'draws' => 0, 'black' => 0, 'moves' => []]);
        }));
        self::getContainer()->get(Connection::class)->executeStatement('DELETE FROM repertoire_explorer_cache');
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheTableIsReadBeforeTheExplorerAndRefreshedAfterThirtyDays(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice);
        $reader = self::getContainer()->get(OpeningStatsReader::class);
        $afterE4 = self::fen('e2e4');
        $reader->store($afterE4, ['total' => 1000, 'moves' => [['uci' => 'e7e5', 'san' => 'e5', 'games' => 600, 'share' => 60.0, 'white' => 300, 'draws' => 100, 'black' => 200]]]);

        $replies = $reader->replies($alice, $afterE4, $fetched);
        self::assertSame([false, 1000, []], [$fetched, $replies['total'], $this->calls], 'from the table, Lichess not asked');

        $this->clock->modify('+31 days');
        $this->explorer[$afterE4] = self::body([['c7c5', 'c5', 900, 300, 200, 400]]);
        $replies = $reader->replies($alice, $afterE4, $fetched);
        self::assertTrue($fetched);
        self::assertCount(1, $this->calls);
        self::assertStringContainsString('speeds=blitz%2Cclassical%2Crapid', $this->calls[0]);
        self::assertStringContainsString('ratings=2000%2C2200%2C2500', $this->calls[0]);
        self::assertSame(['c7c5', 900, 10000], [$replies['moves'][0]['uci'], $replies['moves'][0]['games'], $replies['total']]);
        $stored = $reader->stored($afterE4);
        self::assertNotNull($stored);
        self::assertSame([false, 'c7c5'], [$stored['stale'], $stored['replies']['moves'][0]['uci']], 'refreshed in the table');
    }

    public function testTheCommandPrecomputesTheFirstMovesOfBothSides(): void
    {
        $initial = Rules::initial()->normalizedFen();
        $this->explorer[$initial] = self::body([['e2e4', 'e4', 6000, 3000, 0, 3000], ['d2d4', 'd4', 3000, 1500, 0, 1500], ['h2h4', 'h4', 50, 25, 0, 25]]);
        $this->explorer[self::fen('e2e4')] = self::body([['e7e5', 'e5', 5000, 2500, 0, 2500]]);
        $this->explorer[self::fen('d2d4')] = self::body([['d7d5', 'd5', 5000, 2500, 0, 2500]]);
        $_SERVER['LICHESS_APP_TOKEN'] = $_ENV['LICHESS_APP_TOKEN'] = 'lip_app';
        $application = new Application($this->client->getKernel());
        $command = new CommandTester($application->find('app:repertoire:refresh-opening-stats'));

        try {
            // Two calls allowed: the initial position and 1.e4; 1.d4 waits for the next run.
            $command->execute(['--plies' => '2', '--max-calls' => '2']);
            self::assertStringContainsString('2 written (2 Lichess calls)', $command->getDisplay());
            $command->execute(['--plies' => '2']);
            self::assertStringContainsString('2 fresh, 1 written (1 Lichess calls)', $command->getDisplay());
            self::assertCount(3, $this->calls, 'fresh rows are not asked again; 1.h4 is under the thresholds');
            $rows = self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM repertoire_opening_stats');
            self::assertSame('3', is_numeric($rows) ? (string) $rows : null);
        } finally {
            unset($_SERVER['LICHESS_APP_TOKEN'], $_ENV['LICHESS_APP_TOKEN']);
        }
    }

    public function testExploredRepliesBecomePendingPositionsNeverInTheRepertoire(): void
    {
        $alice = $this->createUser('alice@example.com');
        [$id, $repertoireId] = $this->appliedBuild($alice);

        $pending = $this->pending($alice, $repertoireId);
        self::assertSame(1, $pending['open']);
        $c5 = $pending['items'][0];
        self::assertSame([['e4', 'c5'], 'reply_uncovered', 3000, 'open'], [$c5['path'], $c5['reason'], $c5['games'], $c5['status']]);
        self::assertEqualsWithDelta(30.0, $c5['share'], 0.01, '30 % of the position\'s games');
        $sans = array_column($this->graph($alice, $repertoireId)['moves'], 'san');
        self::assertNotContains('c5', $sans, 'a pending position never enters the repertoire by itself');

        $build = $this->json($this->api('GET', '/api/repertoires/auto-builds/'.$id, $alice));
        self::assertIsArray($build['exploration']);
        self::assertSame([0, false], [$build['exploration']['frontier'], $build['exploration']['capped']]);
    }

    public function testAnsweringAddsABranchInOneUndoableChange(): void
    {
        $alice = $this->createUser('alice@example.com');
        [, $repertoireId] = $this->appliedBuild($alice);
        $c5 = $this->pending($alice, $repertoireId)['items'][0];
        $version = $this->graph($alice, $repertoireId)['version'];

        $response = $this->api('POST', '/api/repertoires/'.$repertoireId.'/pending/'.$c5['id'].'/answer', $alice, ['uci' => 'g1f3', 'baseVersion' => $version]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var ChangeJson $change */
        $change = $this->json($response);
        self::assertSame([$version + 1, 'import'], [$change['version'], $change['operation']]);
        $graph = $this->graph($alice, $repertoireId);
        self::assertContains('c5', array_column($graph['moves'], 'san'));
        self::assertSame('answered', $this->pending($alice, $repertoireId)['items'][0]['status']);

        // Answered: once only.
        self::assertSame(409, $this->api('POST', '/api/repertoires/'.$repertoireId.'/pending/'.$c5['id'].'/answer', $alice, ['uci' => 'b1c3'])->getStatusCode());

        // Undone in one step: the position is pending again.
        self::assertSame(200, $this->api('POST', '/api/repertoires/'.$repertoireId.'/undo', $alice, ['baseVersion' => $version + 1])->getStatusCode());
        self::assertNotContains('c5', array_column($this->graph($alice, $repertoireId)['moves'], 'san'));
        self::assertSame('open', $this->pending($alice, $repertoireId)['items'][0]['status']);

        // An illegal move, a position covered meanwhile in the editor: refused, nothing written.
        self::assertSame(422, $this->api('POST', '/api/repertoires/'.$repertoireId.'/pending/'.$c5['id'].'/answer', $alice, ['uci' => 'e1e8'])->getStatusCode());
        $afterE4 = $this->positionId($alice, $repertoireId, self::fen('e2e4'));
        /** @var ChangeJson $reply */
        $reply = $this->json($this->api('POST', '/api/repertoires/'.$repertoireId.'/lines', $alice, ['fromPositionId' => $afterE4, 'ucis' => ['c7c5', 'b1c3']]));
        $refused = $this->api('POST', '/api/repertoires/'.$repertoireId.'/pending/'.$c5['id'].'/answer', $alice, ['uci' => 'g1f3']);
        self::assertSame(409, $refused->getStatusCode());
        self::assertStringContainsString('position_occupied', (string) $refused->getContent());
        self::assertGreaterThan(0, $reply['version']);
        self::assertSame(['answered', 'b1c3'], [$this->pending($alice, $repertoireId)['items'][0]['status'], $this->pending($alice, $repertoireId)['items'][0]['answerUci']], 'covered in the editor: answered');
    }

    public function testDismissedThenRestored(): void
    {
        $alice = $this->createUser('alice@example.com');
        [, $repertoireId] = $this->appliedBuild($alice);
        $c5 = $this->pending($alice, $repertoireId)['items'][0];
        $url = '/api/repertoires/'.$repertoireId.'/pending/'.$c5['id'];

        /** @var PendingListJson $dismissed */
        $dismissed = $this->json($this->api('POST', $url.'/dismiss', $alice));
        self::assertSame([0, 'dismissed'], [$dismissed['open'], $dismissed['items'][0]['status']]);
        self::assertSame(409, $this->api('POST', $url.'/dismiss', $alice)->getStatusCode());
        self::assertSame(409, $this->api('POST', $url.'/answer', $alice, ['uci' => 'g1f3'])->getStatusCode(), 'a dismissed position is not answered');

        /** @var PendingListJson $restored */
        $restored = $this->json($this->api('POST', $url.'/restore', $alice));
        self::assertSame([1, 'open'], [$restored['open'], $restored['items'][0]['status']]);
        self::assertSame(409, $this->api('POST', $url.'/restore', $alice)->getStatusCode());

        $bob = $this->createUser('bob@example.com');
        self::assertSame(404, $this->api('GET', '/api/repertoires/'.$repertoireId.'/pending', $bob)->getStatusCode());
        self::assertSame(404, $this->api('POST', $url.'/dismiss', $bob)->getStatusCode());
    }

    /**
     * Six Italians with White (1.e4 e5 2.Nf3 Nc6 3.Bc4 Bc5 4.c3), explored: on Lichess 1.e4 is
     * answered by 1...e5 (60 %), 1...c5 (30 %, never met) and 1...e6 (4 %: under the threshold); the
     * short line kept, applied.
     *
     * @return array{string, string} the build and the repertoire created
     */
    private function appliedBuild(User $alice): array
    {
        $this->linkLichess($alice);
        $id = $this->json($this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 50, 'perfs' => ['blitz']]))['id'];
        self::assertIsString($id);
        $lines = [];
        for ($i = 0; $i < 6; ++$i) {
            $lines[] = json_encode([
                'id' => \sprintf('game%04d', $i),
                'variant' => 'standard',
                'perf' => 'blitz',
                'createdAt' => 1790000400000 - $i,
                'players' => ['white' => ['user' => ['name' => 'AliceLichess', 'id' => 'alicelichess'], 'rating' => 1800], 'black' => ['user' => ['name' => 'Opp', 'id' => 'opp'.$i], 'rating' => 1750]],
                'opening' => ['eco' => 'C50', 'name' => 'Italian Game: Giuoco Piano', 'ply' => 4],
                'moves' => 'e4 e5 Nf3 Nc6 Bc4 Bc5 c3',
                'status' => 'resign',
                'winner' => 'white',
            ], \JSON_THROW_ON_ERROR);
        }
        $this->games[] = new MockResponse(implode("\n", $lines)."\n", ['response_headers' => ['content-type' => 'application/x-ndjson']]);
        $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/fetch', $alice);
        $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/selection', $alice, ['families' => [self::ITALIAN]]);

        $this->explorer[self::fen('e2e4')] = self::body([['e7e5', 'e5', 6000, 2500, 1000, 2500], ['c7c5', 'c5', 3000, 1200, 600, 1200], ['e7e6', 'e6', 400, 200, 0, 200]]);
        for ($round = 0; $round < 5; ++$round) {
            $explored = $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/explore', $alice, ['resume' => false]);
            self::assertSame(200, $explored->getStatusCode(), (string) $explored->getContent());
            $build = $this->json($explored);
            self::assertIsArray($build['exploration']);
            if (0 === $build['exploration']['frontier']) {
                break;
            }
        }
        self::assertSame(1, $build['pendingCount']);
        self::assertIsArray($build['trees']);
        /** @var array{key: string, lines: list<array{path: string, short: bool, stop: array{kind: string}}>} $tree */
        $tree = $build['trees'][0];
        self::assertSame([true, 'no_popular_reply'], [$tree['lines'][0]['short'], $tree['lines'][0]['stop']['kind']]);
        $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/selection', $alice, ['families' => [self::ITALIAN], 'kept' => [self::ITALIAN => [$tree['lines'][0]['path']]]]);
        $applied = $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/apply', $alice);
        self::assertSame(200, $applied->getStatusCode(), (string) $applied->getContent());
        $ids = $this->json($applied)['repertoireIds'];
        self::assertIsArray($ids);
        self::assertIsString($ids[0]);

        return [$id, $ids[0]];
    }

    /**
     * @return PendingListJson
     */
    private function pending(User $user, string $repertoireId): array
    {
        $response = $this->api('GET', '/api/repertoires/'.$repertoireId.'/pending', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var PendingListJson */
        return $this->json($response);
    }

    private function positionId(User $user, string $repertoireId, string $fen): string
    {
        foreach ($this->graph($user, $repertoireId)['positions'] as $position) {
            if ($position['fen'] === $fen) {
                return $position['id'];
            }
        }
        self::fail('Position not in the repertoire: '.$fen);
    }

    private function linkLichess(User $user): void
    {
        $identity = new AuthIdentity($user, AuthProvider::Lichess, 'alicelichess');
        self::getContainer()->get(OAuthTokenVault::class)->store($identity, 'lio_alice');
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
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
     * An explorer answer: moves as UCI, SAN, games, White wins, draws, Black wins.
     *
     * @param list<array{string, string, int, int, int, int}> $moves
     *
     * @return array<string, mixed>
     */
    private static function body(array $moves): array
    {
        $white = $draws = $black = 0;
        $list = [];
        foreach ($moves as [$uci, $san, , $w, $d, $b]) {
            $list[] = ['uci' => $uci, 'san' => $san, 'white' => $w, 'draws' => $d, 'black' => $b, 'averageRating' => 2200];
            $white += $w;
            $draws += $d;
            $black += $b;
        }

        // The position's games: those of its moves, and as many again elsewhere would be: 10 000.
        return ['white' => $white, 'draws' => $draws, 'black' => $black + max(0, 10000 - $white - $draws - $black), 'moves' => $list];
    }
}
