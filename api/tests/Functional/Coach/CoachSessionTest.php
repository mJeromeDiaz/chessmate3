<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coach;

use App\Chess\Position\PositionKey;
use App\Chess\Rules;
use App\Coach\CoachRules;
use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Security\OAuth\OAuthTokenVault;
use App\Tests\Functional\Woodpecker\OpeningPuzzlesTrait;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use App\Tests\Unit\Chess\Endgame\EndgameTest;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The tailored session (docs/COACH.md), from the games read on Lichess (simulated, never called) to
 * the coach plan: the diagnosis, the White opening set, the White repertoire (found by its opening,
 * or generated from the same games), the endgames of the weakest kind, the steps and their facts,
 * the plan replaced on a new analysis.
 *
 * Alice: 3 Scotch Games with White (won), 3 Philidor Defenses with Black reaching an endgame of
 * rooks and minor pieces where the engine gave her +3, all drawn.
 */
final class CoachSessionTest extends WoodpeckerWebTestCase
{
    use OpeningPuzzlesTrait;

    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        self::getContainer()->set('lichess_api.client', new MockHttpClient(function (string $method, string $url): MockResponse {
            return array_shift($this->responses) ?? throw new \LogicException('Unexpected call to Lichess: '.$url);
        }));
        $this->addPuzzles('a', 'Scotch_Game', self::WHITE_SOLVES, 110);
        $this->addPuzzles('b', 'Philidor_Defense', self::BLACK_SOLVES, 110);
        $this->catalog->flush();
        $this->catalog->clear();
    }

    public function testFromTheGamesToTheCoachPlan(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $id = $this->readyBuild($alice);

        /** @var array{status: string, games: int, analysed: int, rating: int, white: list<array<string, mixed>>, black: list<array<string, mixed>>, endgames: array{games: int, advantages: int, lost: int, types: list<array<string, mixed>>}, weakest: array<string, mixed>|null} $diagnosis */
        $diagnosis = $this->json($this->api('GET', '/api/coach/builds/'.$id.'/diagnosis', $alice));
        self::assertSame(['ready', 6, 3, 1800], [$diagnosis['status'], $diagnosis['games'], $diagnosis['analysed'], $diagnosis['rating']]);
        self::assertSame([['family' => 'Scotch Game', 'games' => 3, 'wins' => 3, 'draws' => 0, 'losses' => 0]], $diagnosis['white']);
        self::assertSame('Philidor Defense', $diagnosis['black'][0]['family'] ?? null);
        self::assertSame([3, 3, 3], [$diagnosis['endgames']['games'], $diagnosis['endgames']['advantages'], $diagnosis['endgames']['lost']]);
        self::assertSame(['rook_minor', 3], [$diagnosis['weakest']['type'] ?? null, $diagnosis['weakest']['lost'] ?? null]);

        $composition = $this->compose($alice, $id, true);
        self::assertSame(
            [['repertoire', 10], ['evaluation', 2], ['woodpecker', 10], ['brilliancy', 2], ['endgames', 10], ['blindfold', 5]],
            array_map(static fn (array $module): array => [$module['module'], $module['minutes']], $composition['modules']),
        );
        self::assertSame([true, true, true, true, true, true], array_column($composition['modules'], 'included'));
        self::assertSame(39, $composition['minutes']);
        [$repertoire, $evaluation, $woodpecker, $brilliancy, $endgames, $blindfold] = $composition['modules'];
        /** @var array{color: string, opening: array{family: string}, puzzleCount: int, bothColors: bool, settings: array<string, mixed>} $woodpeckerFacts */
        $woodpeckerFacts = $woodpecker['facts'];
        self::assertSame(['white', 'Scotch Game', 100, false], [$woodpeckerFacts['color'], $woodpeckerFacts['opening']['family'], $woodpeckerFacts['puzzleCount'], $woodpeckerFacts['bothColors']]);
        self::assertSame('generated', $composition['repertoire']['status']);
        self::assertSame([['Scotch Game (auto)', 'white']], array_map(static fn (array $r): array => [$r['name'], $r['color']], $composition['repertoire']['repertoires']), 'the White side only');
        self::assertSame(['elo' => 1800, 'side' => 'both'], $evaluation['facts']['settings'] ?? null);
        self::assertSame(['games' => 3, 'advantages' => 3, 'lost' => 3], $evaluation['facts']['endgames'] ?? null);
        self::assertSame([], $brilliancy['facts']['settings'] ?? null);
        self::assertSame(['mode' => 'review', 'themes' => ['rooks'], 'elo' => 1800], $endgames['facts']['settings'] ?? null, 'rooks and minor pieces: the rooks');
        /** @var array{weakest: array{type: string}|null} $endgameFacts */
        $endgameFacts = $endgames['facts'];
        self::assertSame('rook_minor', $endgameFacts['weakest']['type'] ?? null);
        self::assertSame(['level' => 'medium', 'length' => CoachRules::BLINDFOLD_LENGTH, 'visibleSeconds' => CoachRules::BLINDFOLD_VISIBLE_SECONDS], $blindfold['facts']['settings'] ?? null);
        $connection = self::getContainer()->get(Connection::class);
        self::assertEquals(0, $connection->fetchOne("SELECT COUNT(*) FROM woodpecker_set WHERE user_id = ? AND opening_color = 'black'", [$alice->getId()->toBinary()], [ParameterType::BINARY]), 'no Black opening set');

        /** @var array{title: string, origin: string|null, totalMinutes: int, repetition: string, steps: list<array{module: string, settings: array<string, mixed>}>} $plan */
        $plan = $this->json($this->api('GET', '/api/training/plans/'.$composition['planId'], $alice));
        self::assertSame([CoachRules::PLAN_TITLE, 'coach', 39, 'on_demand'], [$plan['title'], $plan['origin'], $plan['totalMinutes'], $plan['repetition']]);
        self::assertSame(['repertoire', 'evaluation', 'woodpecker', 'brilliancy', 'endgames', 'blindfold'], array_column($plan['steps'], 'module'));
        self::assertSame($repertoire['facts']['settings'] ?? null, $plan['steps'][0]['settings']);
        self::assertSame($woodpeckerFacts['settings'], $plan['steps'][2]['settings']);
        // Stored with its keys sorted.
        self::assertEquals($endgames['facts']['settings'], $plan['steps'][4]['settings']);

        // A new analysis: the same plan and sets, the repertoires now existing.
        $again = $this->compose($alice, $id, true);
        self::assertSame([$composition['planId'], 'existing'], [$again['planId'], $again['repertoire']['status']]);
        self::assertSame($composition['repertoire']['repertoires'], $again['repertoire']['repertoires']);
        self::assertSame($woodpeckerFacts['settings'], $again['modules'][2]['facts']['settings'] ?? null);
        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM training_session_plan WHERE user_id = ?', [$alice->getId()->toBinary()]));

        // The session plays.
        $session = $this->api('POST', '/api/training/plans/'.$composition['planId'].'/launch', $alice);
        self::assertSame(201, $session->getStatusCode(), (string) $session->getContent());
    }

    public function testWithoutRepertoireNorPuzzlesTheModulesSayWhy(): void
    {
        $this->catalog->getConnection()->executeStatement("UPDATE puzzle SET selectable = 0 WHERE opening_tags LIKE '%Scotch%'");
        $alice = $this->createUserIn('alice@example.com');
        $id = $this->readyBuild($alice);

        $composition = $this->compose($alice, $id, false);
        self::assertSame(
            [[false, 'not_asked'], [true, null], [false, 'no_puzzles'], [true, null], [true, null], [true, null]],
            array_map(static fn (array $module): array => [$module['included'], $module['missing']], $composition['modules']),
        );
        self::assertSame(19, $composition['minutes']);
    }

    public function testTheRepertoireIsTheWhiteOneOfTheMostPlayedOpening(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $id = $this->readyBuild($alice);
        $scotch = $this->repertoire($alice, 'Écossaise', 'white', ['e2e4', 'e7e5', 'g1f3', 'b8c6', 'd2d4']);
        $london = $this->repertoire($alice, 'Londres', 'white', ['d2d4', 'd7d5', 'c1f4']);
        $this->repertoire($alice, 'Philidor', 'black', ['e2e4', 'e7e5', 'g1f3', 'd7d6']);

        // Without any opening name, the newest White repertoire.
        $connection = self::getContainer()->get(Connection::class);
        $connection->executeStatement('DELETE FROM repertoire_opening');
        $newest = $this->compose($alice, $id, true)['repertoire'];
        self::assertSame(['existing', [$london]], [$newest['status'], array_column($newest['repertoires'], 'id')]);

        // The position after 3.d4 named as Lichess does: the Scotch repertoire, older though it is.
        $rules = Rules::initial();
        foreach (['e2e4', 'e7e5', 'g1f3', 'b8c6', 'd2d4'] as $uci) {
            $rules->playUci($uci);
        }
        $fen = $rules->normalizedFen();
        $connection->executeStatement(
            'INSERT INTO repertoire_opening (eco, name, pgn, uci, epd, epd_hash) VALUES (?, ?, ?, ?, ?, ?)',
            ['C45', 'Scotch Game', '1. e4 e5 2. Nf3 Nc6 3. d4', 'e2e4 e7e5 g1f3 b8c6 d2d4', $fen, PositionKey::of($fen)->hash],
            [ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::STRING, ParameterType::BINARY],
        );
        $composition = $this->compose($alice, $id, true);
        self::assertSame([$scotch], array_column($composition['repertoire']['repertoires'], 'id'));
        self::assertSame(['repertoireIds' => [$scotch]], $composition['modules'][0]['facts']['settings'] ?? null);
    }

    public function testTheGamesMustBeInAndTheBuildTheUsers(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        $this->linkLichess($alice);
        $id = $this->json($this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => CoachRules::GAMES, 'perfs' => CoachRules::PERFS]))['id'] ?? null;
        self::assertIsString($id);

        self::assertSame(409, $this->api('POST', '/api/coach/builds/'.$id.'/session', $alice, ['withRepertoire' => true])->getStatusCode(), 'not_ready');
        self::assertSame(404, $this->api('POST', '/api/coach/builds/'.$id.'/session', $bob, [])->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/coach/builds/'.$id.'/diagnosis', $bob)->getStatusCode());
        $diagnosis = $this->json($this->api('GET', '/api/coach/builds/'.$id.'/diagnosis', $alice));
        self::assertSame(['fetching', 0, null], [$diagnosis['status'] ?? null, $diagnosis['games'] ?? null, $diagnosis['weakest'] ?? null]);
    }

    /**
     * @return array{planId: string, minutes: int, diagnosis: array<string, mixed>, repertoire: array{status: string, repertoires: list<array{id: string, name: string, color: string}>}, modules: list<array{module: string, minutes: int, included: bool, missing: string|null, facts: array<string, mixed>}>}
     */
    private function compose(User $user, string $buildId, bool $withRepertoire): array
    {
        $response = $this->api('POST', '/api/coach/builds/'.$buildId.'/session', $user, ['withRepertoire' => $withRepertoire]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{planId: string, minutes: int, diagnosis: array<string, mixed>, repertoire: array{status: string, repertoires: list<array{id: string, name: string, color: string}>}, modules: list<array{module: string, minutes: int, included: bool, missing: string|null, facts: array<string, mixed>}>} */
        return $this->json($response);
    }

    /**
     * A repertoire made by hand, one line saved.
     *
     * @param list<string> $ucis
     */
    private function repertoire(User $user, string $name, string $color, array $ucis): string
    {
        $id = $this->json($this->api('POST', '/api/repertoires', $user, ['name' => $name, 'color' => $color]))['id'] ?? null;
        self::assertIsString($id);
        $root = $this->json($this->api('GET', '/api/repertoires/'.$id.'/graph', $user))['rootPositionId'] ?? null;
        self::assertSame(200, $this->api('POST', '/api/repertoires/'.$id.'/lines', $user, ['fromPositionId' => $root, 'ucis' => $ucis])->getStatusCode());

        return $id;
    }

    private function readyBuild(User $user): string
    {
        $this->linkLichess($user);
        $id = $this->json($this->api('POST', '/api/repertoires/auto-builds', $user, ['maxGames' => CoachRules::GAMES, 'perfs' => CoachRules::PERFS]))['id'] ?? null;
        self::assertIsString($id);

        $analysis = array_fill(0, 85, ['eval' => 0]);
        // After the 57th ply, the endgame: -3 for White, +3 for Alice with Black.
        $analysis[56] = ['eval' => -300];
        $games = [];
        for ($i = 0; $i < 6; ++$i) {
            $aliceWhite = $i < 3;
            $alice = ['user' => ['name' => 'AliceLichess', 'id' => 'alicelichess'], 'rating' => 1800];
            $opponent = ['user' => ['name' => 'Opp'.$i, 'id' => 'opp'.$i], 'rating' => 1750];
            $games[] = json_encode([
                'id' => \sprintf('game%04d', $i),
                'variant' => 'standard',
                'perf' => 'blitz',
                'createdAt' => 1790000400000 - $i,
                'players' => ['white' => $aliceWhite ? $alice : $opponent, 'black' => $aliceWhite ? $opponent : $alice],
                'opening' => $aliceWhite ? ['eco' => 'C45', 'name' => 'Scotch Game', 'ply' => 5] : ['eco' => 'C41', 'name' => 'Philidor Defense', 'ply' => 4],
                'moves' => $aliceWhite ? 'e4 e5 Nf3 Nc6 d4 exd4 Nxd4' : EndgameTest::RUY_LOPEZ,
                ...($aliceWhite ? ['status' => 'resign', 'winner' => 'white'] : ['status' => 'draw', 'analysis' => $analysis]),
            ], \JSON_THROW_ON_ERROR);
        }
        $this->responses[] = new MockResponse(implode("\n", $games)."\n", ['response_headers' => ['content-type' => 'application/x-ndjson']]);
        $fetched = $this->json($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/fetch', $user));
        self::assertSame('ready', $fetched['status'] ?? null);
        $batch = $fetched['batch'] ?? null;
        self::assertIsArray($batch);
        self::assertSame([null, null, null, 'rook_minor', 'rook_minor', 'rook_minor'], array_column($batch, 'endgame'));

        return $id;
    }

    private function linkLichess(User $user): void
    {
        $identity = new AuthIdentity($user, AuthProvider::Lichess, 'alicelichess');
        self::getContainer()->get(OAuthTokenVault::class)->store($identity, 'lio_alice');
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
    }
}
