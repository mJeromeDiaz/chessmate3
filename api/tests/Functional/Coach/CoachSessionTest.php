<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coach;

use App\Coach\CoachRules;
use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Security\OAuth\OAuthTokenVault;
use App\Tests\Functional\Woodpecker\OpeningPuzzlesTrait;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use App\Tests\Unit\Chess\Endgame\EndgameTest;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * The tailored session (docs/COACH.md), from the games read on Lichess (simulated, never called) to
 * the coach plan: the diagnosis, the opening sets, the repertoire generated from the same games,
 * the steps and their facts, the plan replaced on a new analysis.
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
        self::assertSame([['woodpecker', true], ['woodpecker', true], ['repertoire', true], ['blindfold', true], ['evaluation', true]], array_map(static fn (array $module): array => [$module['module'], $module['included']], $composition['modules']));
        self::assertSame(37, $composition['minutes']);
        [$white, $black, $repertoire, $blindfold, $evaluation] = $composition['modules'];
        /** @var array{color: string, opening: array{family: string}, puzzleCount: int, bothColors: bool, settings: array<string, mixed>} $whiteFacts */
        $whiteFacts = $white['facts'];
        /** @var array{color: string, opening: array{family: string}} $blackFacts */
        $blackFacts = $black['facts'];
        self::assertSame(['white', 'Scotch Game', 100, false], [$whiteFacts['color'], $whiteFacts['opening']['family'], $whiteFacts['puzzleCount'], $whiteFacts['bothColors']]);
        self::assertSame(['black', 'Philidor Defense'], [$blackFacts['color'], $blackFacts['opening']['family']]);
        self::assertSame('generated', $composition['repertoire']['status']);
        self::assertEqualsCanonicalizing(['Scotch Game (auto)', 'Philidor Defense (auto)'], array_column($composition['repertoire']['repertoires'], 'name'));
        self::assertSame(['level' => 'medium', 'length' => CoachRules::BLINDFOLD_LENGTH, 'visibleSeconds' => CoachRules::BLINDFOLD_VISIBLE_SECONDS], $blindfold['facts']['settings'] ?? null);
        self::assertSame(['elo' => 1800, 'side' => 'both'], $evaluation['facts']['settings'] ?? null);
        self::assertSame(['games' => 3, 'advantages' => 3, 'lost' => 3], $evaluation['facts']['endgames'] ?? null);

        /** @var array{title: string, origin: string|null, totalMinutes: int, repetition: string, steps: list<array{module: string, settings: array<string, mixed>}>} $plan */
        $plan = $this->json($this->api('GET', '/api/training/plans/'.$composition['planId'], $alice));
        self::assertSame([CoachRules::PLAN_TITLE, 'coach', 37, 'on_demand'], [$plan['title'], $plan['origin'], $plan['totalMinutes'], $plan['repetition']]);
        self::assertSame(['woodpecker', 'woodpecker', 'repertoire', 'blindfold', 'evaluation'], array_column($plan['steps'], 'module'));
        self::assertSame($whiteFacts['settings'], $plan['steps'][0]['settings']);
        self::assertSame($repertoire['facts']['settings'] ?? null, $plan['steps'][2]['settings']);

        // A new analysis: the same plan and sets, the repertoires now existing.
        $again = $this->compose($alice, $id, true);
        self::assertSame([$composition['planId'], 'existing'], [$again['planId'], $again['repertoire']['status']]);
        self::assertSame($whiteFacts['settings'], $again['modules'][0]['facts']['settings'] ?? null);
        $connection = self::getContainer()->get(Connection::class);
        self::assertEquals(1, $connection->fetchOne('SELECT COUNT(*) FROM training_session_plan WHERE user_id = ?', [$alice->getId()->toBinary()]));

        // The session plays.
        $session = $this->api('POST', '/api/training/plans/'.$composition['planId'].'/launch', $alice);
        self::assertSame(201, $session->getStatusCode(), (string) $session->getContent());
    }

    public function testWithoutRepertoireNorPuzzlesTheModulesSayWhy(): void
    {
        $this->catalog->getConnection()->executeStatement("UPDATE puzzle SET selectable = 0 WHERE opening_tags LIKE '%Philidor%'");
        $alice = $this->createUserIn('alice@example.com');
        $id = $this->readyBuild($alice);

        $composition = $this->compose($alice, $id, false);
        self::assertSame(
            [[true, null], [false, 'no_puzzles'], [false, 'not_asked'], [true, null], [true, null]],
            array_map(static fn (array $module): array => [$module['included'], $module['missing']], $composition['modules']),
        );
        self::assertSame(17, $composition['minutes']);
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
