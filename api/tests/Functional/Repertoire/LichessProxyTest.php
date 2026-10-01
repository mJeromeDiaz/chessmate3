<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Entity\AuthIdentity;
use App\Entity\User;
use App\Enum\AuthProvider;
use App\Repertoire\Lichess\LichessGateway;
use App\Security\OAuth\OAuthTokenVault;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * The Lichess proxy (explorer and cloud eval): token choice, shared cache, one request at a time,
 * pause after a 429, errors. Lichess is never called: its answers are simulated.
 */
final class LichessProxyTest extends RepertoireWebTestCase
{
    private const AFTER_E4 = 'rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq -';
    private const APP_TOKEN = 'lip_application';

    private MockClock $clock;
    private mixed $originalServerToken;
    private mixed $originalEnvToken;
    /** @var list<array{url: string, authorization: string|null}> */
    private array $calls = [];
    /** @var list<MockResponse> */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->clock = new MockClock('2026-09-30 10:00:00', 'UTC');
        Clock::set($this->clock);
        $this->originalServerToken = $_SERVER['LICHESS_APP_TOKEN'] ?? null;
        $this->originalEnvToken = $_ENV['LICHESS_APP_TOKEN'] ?? null;
        $this->setApplicationToken(self::APP_TOKEN);
        $mock = new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array{normalized_headers?: array<string, list<string>>} $options */
            $header = $options['normalized_headers']['authorization'][0] ?? null;
            $this->calls[] = ['url' => $url, 'authorization' => null === $header ? null : substr($header, \strlen('Authorization: '))];

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected call to Lichess: '.$url);
        });
        self::getContainer()->set('lichess_explorer.client', $mock);
        self::getContainer()->set('lichess_api.client', $mock);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        unset($_SERVER['LICHESS_APP_TOKEN'], $_ENV['LICHESS_APP_TOKEN']);
        if (null !== $this->originalServerToken) {
            $_SERVER['LICHESS_APP_TOKEN'] = $this->originalServerToken;
        }
        if (null !== $this->originalEnvToken) {
            $_ENV['LICHESS_APP_TOKEN'] = $this->originalEnvToken;
        }
        parent::tearDown();
    }

    public function testMastersAnswerIsNormalizedAndCachedForEveryone(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $this->responses[] = new JsonMockResponse(self::explorerBody());

        $response = $this->api('GET', '/api/repertoires/explorer/masters?fen='.rawurlencode(self::AFTER_E4.' 0 1'), $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $answer = $this->json($response);
        self::assertSame(self::AFTER_E4, $answer['fen']);
        self::assertSame([12, 10, 8, 30], [$answer['white'], $answer['draws'], $answer['black'], $answer['total']]);
        self::assertSame([
            ['uci' => 'c7c5', 'san' => 'c5', 'white' => 5, 'draws' => 4, 'black' => 3, 'total' => 12, 'averageRating' => 2600],
            ['uci' => 'e7e5', 'san' => 'e5', 'white' => 2, 'draws' => 1, 'black' => 0, 'total' => 3, 'averageRating' => null],
        ], $answer['moves'], 'malformed moves dropped, totals computed');
        self::assertSame(['eco' => 'B00', 'name' => "King's Pawn Game"], $answer['opening']);

        self::assertCount(1, $this->calls);
        $url = $this->calls[0]['url'];
        self::assertStringStartsWith('https://explorer.lichess.org/masters?', $url);
        parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
        self::assertSame(['fen' => self::AFTER_E4.' 0 1', 'moves' => '12', 'topGames' => '0'], $query);
        self::assertSame('Bearer '.self::APP_TOKEN, $this->calls[0]['authorization']);

        $same = $this->api('GET', '/api/repertoires/explorer/masters?fen='.rawurlencode(self::AFTER_E4.' 5 3'), $bob);
        self::assertSame(200, $same->getStatusCode());
        self::assertSame($answer['moves'], $this->json($same)['moves']);
        self::assertCount(1, $this->calls, 'same normalized position: served from the shared cache');
    }

    public function testLichessFiltersAreSortedAndPartOfTheCacheKey(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->responses[] = new JsonMockResponse(self::explorerBody());
        $this->responses[] = new JsonMockResponse(self::explorerBody());
        $fen = rawurlencode(self::AFTER_E4);

        self::assertSame(200, $this->api('GET', '/api/repertoires/explorer/lichess?fen='.$fen.'&speeds=rapid,blitz&ratings=2000,1800', $alice)->getStatusCode());
        parse_str((string) parse_url($this->calls[0]['url'], \PHP_URL_QUERY), $query);
        self::assertSame('blitz,rapid', $query['speeds']);
        self::assertSame('1800,2000', $query['ratings']);
        self::assertSame('0', $query['recentGames']);

        self::assertSame(200, $this->api('GET', '/api/repertoires/explorer/lichess?fen='.$fen.'&speeds=blitz,rapid&ratings=1800,2000', $alice)->getStatusCode());
        self::assertCount(1, $this->calls, 'same filters in another order: cached');
        self::assertSame(200, $this->api('GET', '/api/repertoires/explorer/lichess?fen='.$fen.'&speeds=blitz', $alice)->getStatusCode());
        self::assertCount(2, $this->calls, 'other filters: asked');
    }

    public function testInvalidPositionOrFilterIs422AndNeverReachesLichess(): void
    {
        $alice = $this->createUser('alice@example.com');

        self::assertSame(422, $this->api('GET', '/api/repertoires/explorer/masters?fen=nonsense', $alice)->getStatusCode());
        self::assertSame(422, $this->api('GET', '/api/repertoires/explorer/masters', $alice)->getStatusCode());
        self::assertSame(422, $this->api('GET', '/api/repertoires/explorer/lichess?fen='.rawurlencode(self::AFTER_E4).'&speeds=blitz,daily', $alice)->getStatusCode());
        self::assertSame(422, $this->api('GET', '/api/repertoires/explorer/lichess?fen='.rawurlencode(self::AFTER_E4).'&ratings=1500', $alice)->getStatusCode());
        self::assertSame(422, $this->api('GET', '/api/repertoires/cloud-eval?fen=8/8/8/8/8/8/8/8+w+-+-', $alice)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/repertoires/explorer/player?fen='.rawurlencode(self::AFTER_E4), $alice)->getStatusCode());
        self::assertSame([], $this->calls);
    }

    public function testTheUsersOwnTokenComesFirstAndFallsBackOn401(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice, 'lio_alice');
        $this->responses[] = new MockResponse('{"error":"No such token"}', ['http_code' => 401]);
        $this->responses[] = new JsonMockResponse(self::explorerBody());

        self::assertSame(200, $this->api('GET', '/api/repertoires/explorer/masters?fen='.rawurlencode(self::AFTER_E4), $alice)->getStatusCode());
        self::assertSame(['Bearer lio_alice', 'Bearer '.self::APP_TOKEN], array_column($this->calls, 'authorization'));
    }

    public function testNoTokenAtAllIs503WithAClearMessage(): void
    {
        $this->setApplicationToken('');
        $alice = $this->createUser('alice@example.com');

        $response = $this->api('GET', '/api/repertoires/explorer/masters?fen='.rawurlencode(self::AFTER_E4), $alice);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringContainsString('link yours in your profile', (string) $response->getContent());
        self::assertSame('no_token', $response->headers->get('X-Lichess-Unavailable'));
        self::assertSame([], $this->calls);
    }

    public function testA429PausesEveryCallUntilItEnds(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->responses[] = new MockResponse('', ['http_code' => 429, 'response_headers' => ['Retry-After' => '90']]);

        $limited = $this->api('GET', '/api/repertoires/explorer/masters?fen='.rawurlencode(self::AFTER_E4), $alice);
        self::assertSame(503, $limited->getStatusCode());
        self::assertSame('90', $limited->headers->get('Retry-After'));
        self::assertSame('rate_limited', $limited->headers->get('X-Lichess-Unavailable'));

        $this->clock->sleep(60);
        $paused = $this->api('GET', '/api/repertoires/cloud-eval?fen='.rawurlencode(self::AFTER_E4), $alice);
        self::assertSame(503, $paused->getStatusCode(), 'the pause covers every Lichess endpoint');
        self::assertSame('30', $paused->headers->get('Retry-After'));
        self::assertCount(1, $this->calls, 'Lichess not asked during the pause');

        $this->clock->sleep(31);
        $this->responses[] = new JsonMockResponse(self::explorerBody());
        self::assertSame(200, $this->api('GET', '/api/repertoires/explorer/masters?fen='.rawurlencode(self::AFTER_E4), $alice)->getStatusCode());
        self::assertCount(2, $this->calls);
    }

    public function testA429PausesAtLeastAMinute(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->responses[] = new MockResponse('', ['http_code' => 429]);

        self::assertSame('60', $this->api('GET', '/api/repertoires/explorer/masters?fen='.rawurlencode(self::AFTER_E4), $alice)->headers->get('Retry-After'));
        self::assertSame(LichessGateway::PAUSE_SECONDS + $this->clock->now()->getTimestamp(), self::getContainer()->get(LichessGateway::class)->pausedUntil());
    }

    public function testServerErrorsAreNotCached(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->responses[] = new MockResponse('', ['http_code' => 502]);
        $this->responses[] = new MockResponse('', ['error' => 'Connection timed out']);
        $this->responses[] = new JsonMockResponse(self::explorerBody());
        $uri = '/api/repertoires/explorer/masters?fen='.rawurlencode(self::AFTER_E4);

        self::assertSame(503, $this->api('GET', $uri, $alice)->getStatusCode());
        self::assertSame(503, $this->api('GET', $uri, $alice)->getStatusCode(), 'network error');
        self::assertSame(200, $this->api('GET', $uri, $alice)->getStatusCode());
        self::assertCount(3, $this->calls);
    }

    public function testOneRequestAtATimeAcrossProcesses(): void
    {
        $alice = $this->createUser('alice@example.com');
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        // A second MySQL session, as another PHP process would have (a session may re-take its own lock).
        $other = DriverManager::getConnection($connection->getParams()); // @phpstan-ignore argument.type (getParams() is untyped)
        self::assertEquals(1, $other->fetchOne('SELECT GET_LOCK(?, 0)', [LichessGateway::LOCK_NAME]));

        try {
            $busy = $this->api('GET', '/api/repertoires/explorer/masters?fen='.rawurlencode(self::AFTER_E4), $alice);
            self::assertSame(503, $busy->getStatusCode());
            self::assertSame('busy', $busy->headers->get('X-Lichess-Unavailable'));
            self::assertSame([], $this->calls);
        } finally {
            $other->fetchOne('SELECT RELEASE_LOCK(?)', [LichessGateway::LOCK_NAME]);
            $other->close();
        }

        $this->responses[] = new JsonMockResponse(self::explorerBody());
        self::assertSame(200, $this->api('GET', '/api/repertoires/explorer/masters?fen='.rawurlencode(self::AFTER_E4), $alice)->getStatusCode());
        self::assertEquals(0, $connection->fetchOne('SELECT IS_USED_LOCK(?) IS NOT NULL', [LichessGateway::LOCK_NAME]), 'released after the call');
    }

    public function testCloudEvalSendsNoTokenAndGivesSan(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->linkLichess($alice, 'lio_alice');
        $this->responses[] = new JsonMockResponse([
            'fen' => self::AFTER_E4.' 0 1',
            'knodes' => 123456,
            'depth' => 42,
            'pvs' => [['moves' => 'c7c5 g1f3 d7d6', 'cp' => 25], ['moves' => 'e7e5 g1f3', 'cp' => 30], ['mate' => 3]],
        ]);

        $response = $this->api('GET', '/api/repertoires/cloud-eval?fen='.rawurlencode(self::AFTER_E4).'&lines=9', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([
            'fen' => self::AFTER_E4,
            'found' => true,
            'depth' => 42,
            'knodes' => 123456,
            'lines' => [
                ['cp' => 25, 'mate' => null, 'moves' => ['c7c5', 'g1f3', 'd7d6'], 'san' => ['c5', 'Nf3', 'd6']],
                ['cp' => 30, 'mate' => null, 'moves' => ['e7e5', 'g1f3'], 'san' => ['e5', 'Nf3']],
            ],
        ], array_intersect_key($this->json($response), array_flip(['fen', 'found', 'depth', 'knodes', 'lines'])));
        self::assertStringStartsWith('https://lichess.org/api/cloud-eval?', $this->calls[0]['url']);
        parse_str((string) parse_url($this->calls[0]['url'], \PHP_URL_QUERY), $query);
        self::assertSame('5', $query['multiPv'], 'at most 5 lines');
        self::assertNull($this->calls[0]['authorization']);
    }

    public function testCloudEvalOfAnUnknownPositionIsNotAnError(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->responses[] = new MockResponse('{"error":"Not found"}', ['http_code' => 404]);
        $uri = '/api/repertoires/cloud-eval?fen='.rawurlencode(self::AFTER_E4);

        $response = $this->api('GET', $uri, $alice);
        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->json($response)['found']);
        self::assertSame([], $this->json($response)['lines']);
        self::assertSame(200, $this->api('GET', $uri, $alice)->getStatusCode());
        self::assertCount(1, $this->calls, 'the absence is cached too');
    }

    public function testAnonymousRequestsAreRejectedAndUsersAreRateLimited(): void
    {
        foreach (['/api/repertoires/explorer/masters', '/api/repertoires/cloud-eval'] as $path) {
            $this->client->request('GET', $path.'?fen='.rawurlencode(self::AFTER_E4));
            self::assertSame(401, $this->client->getResponse()->getStatusCode(), $path);
        }

        $alice = $this->createUser('alice@example.com');
        /** @var RateLimiterFactory $limiter */
        $limiter = self::getContainer()->get('limiter.repertoire_explorer');
        $limiter->create($alice->getId()->toRfc4122())->consume(120);
        self::assertSame(429, $this->api('GET', '/api/repertoires/explorer/masters?fen='.rawurlencode(self::AFTER_E4), $alice)->getStatusCode());
        self::assertSame(429, $this->api('GET', '/api/repertoires/cloud-eval?fen='.rawurlencode(self::AFTER_E4), $alice)->getStatusCode());
        self::assertSame([], $this->calls);
    }

    /**
     * @return array<string, mixed>
     */
    private static function explorerBody(): array
    {
        return [
            'white' => 12,
            'draws' => 10,
            'black' => 8,
            'moves' => [
                ['uci' => 'c7c5', 'san' => 'c5', 'white' => 5, 'draws' => 4, 'black' => 3, 'averageRating' => 2600],
                ['uci' => 'e7e5', 'san' => 'e5', 'white' => 2, 'draws' => 1],
                ['san' => 'd5'],
            ],
            'topGames' => [],
            'opening' => ['eco' => 'B00', 'name' => "King's Pawn Game"],
        ];
    }

    private function linkLichess(User $user, string $token): void
    {
        $identity = new AuthIdentity($user, AuthProvider::Lichess, strtolower(explode('@', (string) $user->getEmail())[0]).'lichess');
        self::getContainer()->get(OAuthTokenVault::class)->store($identity, $token);
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
    }

    /**
     * LICHESS_APP_TOKEN as the kernel reads it (resolved when the service is first built).
     */
    private function setApplicationToken(string $token): void
    {
        $_SERVER['LICHESS_APP_TOKEN'] = $_ENV['LICHESS_APP_TOKEN'] = $token;
    }
}
