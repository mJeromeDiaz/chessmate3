<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Security\OAuth\OAuthTokenVault;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Repertoire from my games, reading part: settings, linked account, batches of games from Lichess
 * (simulated, never called), synthesis.
 *
 * @phpstan-type FamilyJson array{key: string, family: string, color: string, games: int, wins: int, draws: int, losses: int, analysed: int}
 * @phpstan-type SynthesisJson array{games: int, analysed: int, wins: int, draws: int, losses: int, families: list<FamilyJson>}
 * @phpstan-type BuildJson array{id: string, status: string, username: string, maxGames: int, perfs: list<string>, games: int, scanned: int, error: string|null, batch: list<array<string, mixed>>|null, synthesis: SynthesisJson|null, repertoireIds: list<string>, expiresAt: string}
 */
final class AutoBuildApiTest extends RepertoireWebTestCase
{
    /** @var list<array{url: string, authorization: string|null, accept: string|null}> */
    private array $calls = [];
    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        self::getContainer()->set('lichess_api.client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array{normalized_headers?: array<string, list<string>>} $options */
            $authorization = $options['normalized_headers']['authorization'][0] ?? null;
            $accept = $options['normalized_headers']['accept'][0] ?? null;
            $this->calls[] = [
                'url' => $url,
                'authorization' => null === $authorization ? null : substr($authorization, \strlen('Authorization: ')),
                'accept' => null === $accept ? null : substr($accept, \strlen('Accept: ')),
            ];

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected call to Lichess: '.$url);
        }));
    }

    public function testAnAccountMustBeLinked(): void
    {
        $alice = $this->createUser('alice@example.com');

        $response = $this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 50, 'perfs' => ['blitz']]);

        self::assertSame(409, $response->getStatusCode());
        self::assertStringContainsString('lichess_not_linked', (string) $response->getContent());
        self::assertSame([], $this->calls);
    }

    public function testSettingsAreChecked(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice);

        self::assertSame(422, $this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 30, 'perfs' => ['blitz']])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 5000, 'perfs' => ['blitz']])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 50, 'perfs' => []])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 50, 'perfs' => ['ultraBullet']])->getStatusCode());
    }

    public function testGamesAreReadByBatchThenSummarized(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice);

        $created = $this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 50, 'perfs' => ['blitz', 'rapid']]);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getContent());
        $build = $this->build($created);
        self::assertSame(['fetching', 'alicelichess', 50, ['blitz', 'rapid'], 0, null], [$build['status'], $build['username'], $build['maxGames'], $build['perfs'], $build['games'], $build['synthesis']]);

        $this->responses[] = self::games();
        $fetched = $this->api('POST', '/api/repertoires/auto-builds/'.$build['id'].'/fetch', $alice);
        self::assertSame(200, $fetched->getStatusCode(), (string) $fetched->getContent());
        $build = $this->build($fetched);

        self::assertCount(1, $this->calls);
        self::assertStringStartsWith('https://lichess.org/api/games/user/alicelichess?', $this->calls[0]['url']);
        parse_str((string) parse_url($this->calls[0]['url'], \PHP_URL_QUERY), $query);
        self::assertSame(['max' => '50', 'perfType' => 'blitz,rapid', 'finished' => 'true', 'moves' => 'true', 'clocks' => 'true', 'evals' => 'true', 'opening' => 'true', 'pgnInJson' => 'false'], $query);
        self::assertSame(['Bearer lio_alice', 'application/x-ndjson'], [$this->calls[0]['authorization'], $this->calls[0]['accept']]);

        // 5 games sent: a chess960 one and an aborted one left out; fewer than asked: nothing more to read.
        self::assertSame(['ready', 3, 5], [$build['status'], $build['games'], $build['scanned']]);
        $batch = $build['batch'];
        self::assertNotNull($batch);
        self::assertSame(['game0001', 'game0002', 'game0005'], array_column($batch, 'id'));
        self::assertSame(['id' => 'game0001', 'color' => 'white', 'result' => 1, 'opponent' => 'Pion_Furtif', 'rating' => 1795, 'family' => 'Italian Game', 'perf' => 'blitz', 'endgame' => null], $batch[0]);
        $synthesis = $build['synthesis'];
        self::assertNotNull($synthesis);
        self::assertSame([3, 1, 1, 1, 1], [$synthesis['games'], $synthesis['analysed'], $synthesis['wins'], $synthesis['draws'], $synthesis['losses']]);
        self::assertSame([], $synthesis['families'], 'no family played 3 times');

        // Ready: fetching again asks nothing of Lichess.
        $again = $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$build['id'].'/fetch', $alice));
        self::assertSame([], $again['batch']);
        self::assertCount(1, $this->calls);

        $read = $this->build($this->api('GET', '/api/repertoires/auto-builds/'.$build['id'], $alice));
        self::assertSame(['ready', 3, null], [$read['status'], $read['games'], $read['batch']]);
    }

    public function testTheCursorMovesBeforeTheOldestGameAndStopsAtTheGamesWanted(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice);
        $id = $this->build($this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 100, 'perfs' => ['blitz']]))['id'];

        // A full batch of 50 (the same game 50 times, 1 ms apart): more may come.
        $this->responses[] = self::repeated(50, 1790000400000);
        $build = $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/fetch', $alice));
        self::assertSame(['fetching', 50], [$build['status'], $build['games']]);
        self::assertNull($build['synthesis']);

        $this->responses[] = self::repeated(50, 1790000300000);
        $build = $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/fetch', $alice));
        parse_str((string) parse_url($this->calls[1]['url'], \PHP_URL_QUERY), $query);
        self::assertSame(['50', (string) (1790000400000 - 49 - 1)], [$query['max'], $query['until']]);
        self::assertSame(['ready', 100], [$build['status'], $build['games']]);
        $synthesis = $build['synthesis'];
        self::assertNotNull($synthesis);
        self::assertSame(['white:Italian Game'], array_column($synthesis['families'], 'key'));
        self::assertSame(100, $synthesis['families'][0]['games']);
    }

    public function testLichessUnavailableAnswers503AndTheBuildWaits(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice);
        $id = $this->build($this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 50, 'perfs' => ['blitz']]))['id'];

        $this->responses[] = new MockResponse('', ['http_code' => 429]);
        $response = $this->api('POST', '/api/repertoires/auto-builds/'.$id.'/fetch', $alice);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('rate_limited', $response->headers->get('X-Lichess-Unavailable'));
        self::assertSame('fetching', $this->build($this->api('GET', '/api/repertoires/auto-builds/'.$id, $alice))['status']);
    }

    public function testAnUnknownLichessAccountEndsTheBuild(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice);
        $id = $this->build($this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 50, 'perfs' => ['blitz']]))['id'];

        $this->responses[] = new MockResponse('Not found', ['http_code' => 404]);
        $build = $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/fetch', $alice));

        self::assertSame(['ready', 'lichess_user_not_found', 0], [$build['status'], $build['error'], $build['games']]);
    }

    public function testARevokedTokenFallsBackToAnAnonymousRequest(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice);
        $id = $this->build($this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 50, 'perfs' => ['blitz']]))['id'];

        $this->responses[] = new MockResponse('', ['http_code' => 401]);
        $this->responses[] = self::games();
        $build = $this->build($this->api('POST', '/api/repertoires/auto-builds/'.$id.'/fetch', $alice));

        self::assertSame(['Bearer lio_alice', null], array_column($this->calls, 'authorization'));
        self::assertSame(3, $build['games']);
    }

    public function testABuildIsItsOwnersOnlyAndANewOneReplacesIt(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->linkLichess($alice);
        $first = $this->build($this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 50, 'perfs' => ['blitz']]))['id'];

        self::assertSame(404, $this->api('GET', '/api/repertoires/auto-builds/'.$first, $bob)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/repertoires/auto-builds/'.$first.'/fetch', $bob)->getStatusCode());

        $second = $this->build($this->api('POST', '/api/repertoires/auto-builds', $alice, ['maxGames' => 100, 'perfs' => ['rapid']]))['id'];
        $this->entityManager->clear();
        self::assertSame(404, $this->api('GET', '/api/repertoires/auto-builds/'.$first, $alice)->getStatusCode());
        self::assertSame(200, $this->api('GET', '/api/repertoires/auto-builds/'.$second, $alice)->getStatusCode());
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
        $identity = new AuthIdentity($user, AuthProvider::Lichess, 'AliceLichess');
        self::getContainer()->get(OAuthTokenVault::class)->store($identity, 'lio_alice');
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
    }

    private static function games(): MockResponse
    {
        return new MockResponse((string) file_get_contents(__DIR__.'/../../Fixtures/Repertoire/lichess-games.ndjson'), ['response_headers' => ['content-type' => 'application/x-ndjson']]);
    }

    /**
     * The fixture's first game (Alice's Italian win) $count times, newest first, 1 ms apart.
     */
    private static function repeated(int $count, int $newest): MockResponse
    {
        $lines = file(__DIR__.'/../../Fixtures/Repertoire/lichess-games.ndjson', \FILE_IGNORE_NEW_LINES) ?: [];
        $game = json_decode($lines[0], true, 16, \JSON_THROW_ON_ERROR);
        \assert(\is_array($game));
        $body = '';
        for ($i = 0; $i < $count; ++$i) {
            $body .= json_encode([...$game, 'id' => \sprintf('g%07d', $newest - $i), 'createdAt' => $newest - $i], \JSON_THROW_ON_ERROR)."\n";
        }

        return new MockResponse($body, ['response_headers' => ['content-type' => 'application/x-ndjson']]);
    }
}
