<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Enum\Repertoire\Color;
use App\Repertoire\RepertoireManager;
use App\Security\OAuth\OAuthTokenVault;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Repertoire from my games, from the selection to the repertoires: trees of the openings kept,
 * removed lines, cloud evaluation of the line ends (Lichess simulated, never called), tags,
 * application, statistics kept.
 *
 * @phpstan-type LineJson array{path: string, cut: string, name: string, nodes: list<string>, games: int, wins: int, draws: int, losses: int, analysed: int, errored: int, fen: string, depth: int, short: bool, stop: array{kind: string, san: string|null, path: string|null}, confirmed: bool, number: int, tag: string, evaluated: bool, evalCp: int|null, evalMate: int|null, locked: bool}
 * @phpstan-type PendingJson array{path: string, parent: string, depth: int, fen: string, uci: string, san: string, sans: list<string>, reason: string, games: int|null, share: float|null, score: int|null}
 * @phpstan-type TreeJson array{key: string, family: string, color: string, games: int, wins: int, draws: int, losses: int, trunk: int, nodes: list<array<string, mixed>>, lines: list<LineJson>, pending: list<PendingJson>}
 * @phpstan-type BuildJson array{id: string, status: string, selection: array<string, mixed>|null, trees: list<TreeJson>, pendingEvaluations: int, freeLines: int|null, repertoireIds: list<string>, exploration: array{calls: int, budget: int, capped: bool, frontier: int, explored: int}|null, unconfirmedLines: int, pendingCount: int}
 */
final class AutoBuildFlowTest extends RepertoireWebTestCase
{
    private const ITALIAN = 'white:Italian Game';
    private const QGD = "black:Queen's Gambit Declined";
    private const TWO_KNIGHTS = 'e2e4 e7e5 g1f3 b8c6 f1c4 g8f6';
    /** How the simulated games end (Lichess: no winner field on a draw). */
    private const WHITE_WINS = ['status' => 'resign', 'winner' => 'white'];
    private const BLACK_WINS = ['status' => 'resign', 'winner' => 'black'];
    private const DRAW = ['status' => 'draw'];

    /** @var list<string> */
    private array $calls = [];
    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        self::getContainer()->set('lichess_api.client', new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->calls[] = $url;

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected call to Lichess: '.$url);
        }));
    }

    public function testFromTheSelectionToTheRepertoires(): void
    {
        $alice = $this->createUser('alice@example.com');
        $id = $this->readyBuild($alice);

        $build = $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/selection', $alice, ['families' => [self::ITALIAN, self::QGD]]));
        self::assertSame([self::ITALIAN, self::QGD], array_column($build['trees'], 'key'));
        [$italian, $qgd] = $build['trees'];
        self::assertSame(['Italian Game', 'white', 6, 5], [$italian['family'], $italian['color'], $italian['games'], $italian['trunk']]);
        self::assertSame(['Giuoco Piano', 'Two Knights Defense'], array_column($italian['lines'], 'name'));
        self::assertSame([1, 2, 3], [...array_column($italian['lines'], 'number'), ...array_column($qgd['lines'], 'number')]);
        self::assertSame([3, false, false], [$build['pendingEvaluations'], $italian['lines'][0]['evaluated'], $italian['lines'][0]['locked']]);
        self::assertNull($build['freeLines']);

        // Three line ends, one request: +0.4 (White), none, +0.5 (White, so -0.5 for Alice with Black).
        $this->responses[] = self::cloud(40);
        $this->responses[] = new MockResponse('{}', ['http_code' => 404]);
        $this->responses[] = self::cloud(50);
        $build = $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/evaluate', $alice));
        self::assertSame(0, $build['pendingEvaluations']);
        self::assertCount(3, $this->calls);
        self::assertStringContainsString('/api/cloud-eval?', $this->calls[0]);
        [$italian, $qgd] = $build['trees'];
        $lines = [...$italian['lines'], ...$qgd['lines']];
        self::assertSame([true, true, true], array_column($lines, 'evaluated'));
        self::assertSame([40, null, -50], array_column($lines, 'evalCp'));
        // 3 wins and ahead; 2 losses and a draw; 3 draws, a little behind.
        self::assertSame(['weapon', 'risk', 'draws'], array_column($lines, 'tag'));

        // Every line is short (under 10 moves): not applied before the user kept them.
        self::assertSame([true, true, true], array_column($lines, 'short'));
        self::assertSame(3, $build['unconfirmedLines']);
        $refused = $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/apply', $alice);
        self::assertSame(422, $refused->getStatusCode());
        self::assertStringContainsString('unconfirmed_lines', (string) $refused->getContent());
        self::assertSame(0, $this->keepShortLines($alice, $id, $build)['unconfirmedLines']);

        $applied = $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/apply', $alice);
        self::assertSame(200, $applied->getStatusCode(), (string) $applied->getContent());
        $build = $this->build($applied);
        self::assertSame('applied', $build['status']);
        self::assertCount(2, $build['repertoireIds']);

        $connection = self::getContainer()->get(Connection::class);
        /** @var list<array{name: string, color: string, position_count: int|string, origin: string}> $repertoires */
        $repertoires = $connection->fetchAllAssociative('SELECT name, color, position_count, origin FROM repertoire WHERE user_id = ? ORDER BY name', [$alice->getId()->toBinary()]);
        self::assertSame(['Italian Game (auto)', 'white', 10], [$repertoires[0]['name'], $repertoires[0]['color'], (int) $repertoires[0]['position_count']]);
        self::assertSame(["Queen's Gambit Declined (auto)", 'black', 5], [$repertoires[1]['name'], $repertoires[1]['color'], (int) $repertoires[1]['position_count']]);
        $origin = json_decode($repertoires[0]['origin'], true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($origin);
        self::assertSame(['lichess_games', 'alicelichess', 6, ['blitz']], [$origin['source'], $origin['username'], $origin['games'], $origin['perfs']]);

        /** @var list<array{uci: string, games: int|string, wins: int|string, draws: int|string, losses: int|string, eval_cp: int|string|null}> $stats */
        $stats = $connection->fetchAllAssociative(
            'SELECT s.uci, s.games, s.wins, s.draws, s.losses, s.eval_cp FROM repertoire_game_stat s JOIN repertoire r ON r.id = s.repertoire_id WHERE r.color = ? AND r.user_id = ? ORDER BY s.games DESC, s.uci',
            ['white', $alice->getId()->toBinary()],
        );
        self::assertCount(9, $stats, '1.e4 e5 2.Nf3 Nc6 3.Bc4, then 3...Bc5 4.c3 or 3...Nf6 4.Ng5');
        $byUci = array_column($stats, null, 'uci');
        self::assertSame([6, 3, 1, 2, null], [(int) $byUci['f1c4']['games'], (int) $byUci['f1c4']['wins'], (int) $byUci['f1c4']['draws'], (int) $byUci['f1c4']['losses'], $byUci['f1c4']['eval_cp']]);
        self::assertSame([3, null], [(int) $byUci['f8c5']['games'], $byUci['f8c5']['eval_cp']], 'evaluations on the line ends only');
        self::assertSame([3, 40], [(int) $byUci['c2c3']['games'], (int) $byUci['c2c3']['eval_cp']]);
        self::assertNull($byUci['f3g5']['eval_cp'], 'Lichess had no evaluation');

        self::assertSame(409, $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/apply', $alice)->getStatusCode());
        self::assertSame(409, $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/selection', $alice, ['families' => [self::ITALIAN]])->getStatusCode());

        // The editor gets the origin and the statistics, by position and move.
        $italianId = null;
        foreach ($build['repertoireIds'] as $repertoireId) {
            $graph = $this->json($this->api('GET', '/api/repertoires/'.$repertoireId.'/graph', $alice));
            if ('white' === $graph['color']) {
                $italianId = $repertoireId;
                self::assertIsArray($graph['origin']);
                self::assertSame('alicelichess', $graph['origin']['username'] ?? null);
                /** @var list<array{uci: string, games: int, wins: int, draws: int, losses: int}> $stats */
                $stats = $graph['gameStats'];
                self::assertCount(9, $stats);
                $bc4 = array_values(array_filter($stats, static fn (array $row): bool => 'f1c4' === $row['uci']));
                self::assertCount(1, $bc4);
                self::assertSame([6, 3, 1, 2], [$bc4[0]['games'], $bc4[0]['wins'], $bc4[0]['draws'], $bc4[0]['losses']]);
            }
        }
        self::assertNotNull($italianId);
        $listed = $this->json($this->api('GET', '/api/repertoires/'.$italianId, $alice));
        self::assertSame('lichess_games', \is_array($listed['origin']) ? ($listed['origin']['source'] ?? null) : null);

        $byHand = $this->createRepertoireVia($alice, 'À la main');
        $graph = $this->json($this->api('GET', '/api/repertoires/'.$byHand['id'].'/graph', $alice));
        self::assertSame([null, []], [$graph['origin'], $graph['gameStats']]);
    }

    public function testARemovedLineIsLeftOutAndCanComeBack(): void
    {
        $alice = $this->createUser('alice@example.com');
        $id = $this->readyBuild($alice);

        $build = $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/selection', $alice, [
            'families' => [self::ITALIAN],
            'removed' => [self::ITALIAN => [self::TWO_KNIGHTS]],
        ]));
        self::assertSame(['Giuoco Piano'], array_column($build['trees'][0]['lines'], 'name'));
        self::assertSame([self::ITALIAN => [self::TWO_KNIGHTS]], $build['selection']['removed'] ?? null);

        $build = $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/selection', $alice, ['families' => [self::ITALIAN]]));
        self::assertCount(2, $build['trees'][0]['lines']);
    }

    public function testTheSelectionIsChecked(): void
    {
        $alice = $this->createUser('alice@example.com');
        $id = $this->readyBuild($alice);
        $url = '/api/repertoires/auto-builds/'.$id.'/selection';

        foreach ([
            ['families' => ['white:Sicilian Defense']],
            ['families' => [self::ITALIAN], 'removed' => [self::QGD => ['d2d4']]],
            ['families' => [self::ITALIAN], 'removed' => [self::ITALIAN => ['e4 e5']]],
            ['families' => [self::ITALIAN], 'choices' => [self::ITALIAN => ['e2e4 e7e5' => 'Nf3']]],
        ] as $selection) {
            $response = $this->api('POST', $url, $alice, $selection);
            self::assertSame(422, $response->getStatusCode(), json_encode($selection, \JSON_THROW_ON_ERROR));
            self::assertStringContainsString('invalid_selection', (string) $response->getContent());
        }

        $nothing = $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/apply', $alice);
        self::assertSame(422, $nothing->getStatusCode());
        self::assertStringContainsString('nothing_selected', (string) $nothing->getContent());
    }

    public function testNothingCanBeChosenBeforeEveryGameIsIn(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice);
        $id = $this->build($this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 50, 'perfs' => ['blitz']]))['id'];

        $response = $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/selection', $alice, ['families' => []]);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('not_ready', (string) $response->getContent());
    }

    public function testTheRepertoireLimitIsCheckedBeforeAnything(): void
    {
        $alice = $this->createUser('alice@example.com');
        $id = $this->readyBuild($alice);
        $this->keepShortLines($alice, $id, $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/selection', $alice, ['families' => [self::ITALIAN, self::QGD]])));
        $manager = self::getContainer()->get(RepertoireManager::class);
        // The requests above left $alice detached: the manager needs a managed user.
        $managed = $this->entityManager->find(User::class, $alice->getId());
        self::assertNotNull($managed);
        for ($i = 0; $i < 49; ++$i) {
            $manager->create($managed, 'R'.$i, Color::White);
        }

        $response = $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/apply', $alice);

        self::assertSame(422, $response->getStatusCode());
        self::assertStringContainsString('limit_repertoires', (string) $response->getContent());
        $count = self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM repertoire WHERE user_id = ?', [$alice->getId()->toBinary()]);
        self::assertSame('49', is_numeric($count) ? (string) $count : null);
    }

    /**
     * A ready build of 9 blitz games: 6 Italians with White (3...Bc5 4.c3: 3 wins; 3...Nf6 4.Ng5:
     * 2 losses and a draw), 3 Queen's Gambits Declined with Black (1.d4 d5 2.c4 e6: 3 draws).
     */
    private function readyBuild(User $user): string
    {
        $this->linkLichess($user);
        $id = $this->build($this->api('POST', '/api/repertoires/auto-builds', $user, ['maxGames' => 50, 'perfs' => ['blitz']]))['id'];
        $games = [];
        $at = 1790000400000;
        foreach ([
            [true, self::WHITE_WINS, 'Italian Game: Giuoco Piano', 'e4 e5 Nf3 Nc6 Bc4 Bc5 c3'],
            [true, self::WHITE_WINS, 'Italian Game: Giuoco Piano', 'e4 e5 Nf3 Nc6 Bc4 Bc5 c3'],
            [true, self::WHITE_WINS, 'Italian Game: Giuoco Piano', 'e4 e5 Nf3 Nc6 Bc4 Bc5 c3'],
            [true, self::BLACK_WINS, 'Italian Game: Two Knights Defense', 'e4 e5 Nf3 Nc6 Bc4 Nf6 Ng5'],
            [true, self::BLACK_WINS, 'Italian Game: Two Knights Defense', 'e4 e5 Nf3 Nc6 Bc4 Nf6 Ng5'],
            [true, self::DRAW, 'Italian Game: Two Knights Defense', 'e4 e5 Nf3 Nc6 Bc4 Nf6 Ng5'],
            [false, self::DRAW, "Queen's Gambit Declined", 'd4 d5 c4 e6'],
            [false, self::DRAW, "Queen's Gambit Declined", 'd4 d5 c4 e6'],
            [false, self::DRAW, "Queen's Gambit Declined", 'd4 d5 c4 e6'],
        ] as $i => [$aliceWhite, $result, $opening, $moves]) {
            $alice = ['user' => ['name' => 'AliceLichess', 'id' => 'alicelichess'], 'rating' => 1800];
            $opponent = ['user' => ['name' => 'Opp'.$i, 'id' => 'opp'.$i], 'rating' => 1750];
            $game = [
                'id' => \sprintf('game%04d', $i),
                'variant' => 'standard',
                'perf' => 'blitz',
                'createdAt' => $at - $i,
                'players' => ['white' => $aliceWhite ? $alice : $opponent, 'black' => $aliceWhite ? $opponent : $alice],
                'opening' => ['eco' => 'C50', 'name' => $opening, 'ply' => 4],
                'moves' => $moves,
                ...$result,
            ];
            $games[] = json_encode($game, \JSON_THROW_ON_ERROR);
        }
        $this->responses[] = new MockResponse(implode("\n", $games)."\n", ['response_headers' => ['content-type' => 'application/x-ndjson']]);
        $build = $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/fetch', $user));
        self::assertSame('ready', $build['status']);
        $this->calls = [];

        return $id;
    }

    /**
     * Keeps every short line of the build (the selection is sent again with them).
     *
     * @param BuildJson $build
     *
     * @return BuildJson
     */
    private function keepShortLines(User $user, string $id, array $build): array
    {
        $kept = [];
        foreach ($build['trees'] as $tree) {
            foreach ($tree['lines'] as $line) {
                if ($line['short']) {
                    $kept[$tree['key']][] = $line['path'];
                }
            }
        }
        $selection = $build['selection'] ?? [];

        return $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/selection', $user, [...$selection, 'kept' => $kept]));
    }

    private static function cloud(int $cp): JsonMockResponse
    {
        return new JsonMockResponse(['depth' => 30, 'knodes' => 1000, 'pvs' => [['moves' => '', 'cp' => $cp]]]);
    }

    /**
     * @return BuildJson
     */
    private function build(Response $response): array
    {
        /** @var BuildJson */
        return $this->json($response);
    }

    private function linkLichess(User $user): void
    {
        $identity = new AuthIdentity($user, AuthProvider::Lichess, 'alicelichess');
        self::getContainer()->get(OAuthTokenVault::class)->store($identity, 'lio_alice');
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
    }
}
