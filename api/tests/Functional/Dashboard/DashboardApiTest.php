<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\Activity\LogEntry;
use App\Entity\AuthIdentity;
use App\Entity\Puzzle\RatingChange;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\AuthProvider;
use App\Enum\Puzzle\RatingChangeReason;
use App\Puzzle\Rating\RatingState;
use App\Security\OAuth\OAuthTokenVault;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dashboard endpoints (docs/DASHBOARD.md): local days, the user's data only, the Lichess rating
 * history (simulated, never called for real), errors.
 */
final class DashboardApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    /** @var list<array{url: string, authorization: string|null}> */
    private array $calls = [];
    /** @var list<MockResponse> */
    private array $responses = [];
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $container->get('cache.rate_limiter')->clear();
        $container->get('repertoire.explorer_cache')->clear();
        Clock::set(new MockClock('2026-07-20 10:00:00', 'UTC'));
        $container->set('lichess_api.client', new MockHttpClient(function (string $method, string $url, array $options): MockResponse {
            /** @var array{normalized_headers?: array<string, list<string>>} $options */
            $header = $options['normalized_headers']['authorization'][0] ?? null;
            $this->calls[] = ['url' => $url, 'authorization' => null === $header ? null : substr($header, \strlen('Authorization: '))];

            return array_shift($this->responses) ?? throw new \LogicException('Unexpected call to Lichess: '.$url);
        }));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testActivityIsCountedOnTheUsersLocalDaysAndOnlyTheirs(): void
    {
        $alice = $this->createUser('alice@example.com', 'Europe/Paris');
        $bob = $this->createUser('bob@example.com', 'Europe/Paris');
        $this->log($alice, ExerciseType::PuzzleRated, true, '2026-07-13 21:30:00', 30_000); // 07-13 23:30 Paris: before the period
        $this->log($alice, ExerciseType::PuzzleRated, false, '2026-07-13 22:30:00', 20_000); // 07-14 00:30 Paris
        $this->log($alice, ExerciseType::WoodpeckerPuzzle, true, '2026-07-14 22:30:00', 10_000); // 07-15 00:30 Paris
        $this->log($alice, ExerciseType::PuzzleRated, true, '2026-07-15 08:00:00', 5_000);
        $this->log($bob, ExerciseType::PuzzleRated, true, '2026-07-15 08:00:00', 5_000);

        $response = $this->api('/api/dashboard/activity?days=7', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame('Europe/Paris', $body['timezone']);
        self::assertSame(['2026-07-14', '2026-07-20'], [$body['from'], $body['today']]);
        self::assertSame([
            ['date' => '2026-07-14', 'count' => 1, 'successCount' => 0, 'durationMs' => 20_000],
            ['date' => '2026-07-15', 'count' => 2, 'successCount' => 2, 'durationMs' => 15_000],
        ], $body['days']);
        self::assertSame([
            'puzzle_rated' => ['count' => 3, 'successCount' => 2, 'durationMs' => 55_000],
            'woodpecker_puzzle' => ['count' => 1, 'successCount' => 1, 'durationMs' => 10_000],
        ], $body['totals'], 'all-time totals, Bob excluded');
    }

    public function testTodayFollowsTheUsersTimezoneAndThePeriodIsClamped(): void
    {
        Clock::set(new MockClock('2026-07-20 23:30:00', 'UTC'));
        $tokyo = $this->createUser('tokyo@example.com', 'Asia/Tokyo');

        $body = $this->json($this->api('/api/dashboard/activity?days=1', $tokyo));
        self::assertSame(['2026-07-15', '2026-07-21'], [$body['from'], $body['today']], 'already the 21st in Tokyo; at least 7 days');
        self::assertSame([], $body['days']);
        self::assertSame([], $body['totals']);

        $body = $this->json($this->api('/api/dashboard/activity?days=5000', $tokyo));
        self::assertSame('2025-07-16', $body['from'], 'at most 371 days');
        self::assertSame('2026-04-29', $this->json($this->api('/api/dashboard/activity', $tokyo))['from'], '84 days by default');
    }

    public function testRatingHistoryKeepsTheLastRatingOfEachLocalDay(): void
    {
        $alice = $this->createUser('alice@example.com', 'Europe/Paris');
        $bob = $this->createUser('bob@example.com', 'UTC');
        $this->rate($alice, 1500, 1480, '2026-07-10 12:00:00'); // before the period: opens it
        $this->rate($alice, 1480, 1490, '2026-07-15 08:00:00');
        $this->rate($alice, 1490, 1512.6, '2026-07-15 21:59:00'); // 23:59 Paris: still the 15th
        $this->rate($alice, 1512.6, 1505, '2026-07-15 22:30:00'); // the 16th in Paris
        $this->rate($bob, 1500, 1700, '2026-07-16 08:00:00');

        $response = $this->api('/api/dashboard/rating-history?days=7', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame(['2026-07-14', '2026-07-20'], [$body['from'], $body['today']]);
        self::assertSame([
            ['date' => '2026-07-14', 'rating' => 1480],
            ['date' => '2026-07-15', 'rating' => 1513],
            ['date' => '2026-07-16', 'rating' => 1505],
        ], $body['points']);

        self::assertSame([], $this->json($this->api('/api/dashboard/rating-history', $this->createUser('new@example.com', 'UTC')))['points']);
    }

    public function testLichessHistoryIsNotAskedWithoutALinkedAccount(): void
    {
        $alice = $this->createUser('alice@example.com', 'UTC');

        $body = $this->json($this->api('/api/dashboard/lichess-rating-history', $alice));
        self::assertFalse($body['linked']);
        self::assertNull($body['username']);
        self::assertSame(['blitz' => [], 'rapid' => [], 'classical' => []], $body['perfs']);
        self::assertSame([], $this->calls);
    }

    public function testLichessHistoryIsNormalizedWindowedAndCached(): void
    {
        $alice = $this->createUser('alice@example.com', 'UTC');
        $this->linkLichess($alice, 'AliceL', 'lio_alice');
        $this->responses[] = new JsonMockResponse([
            ['name' => 'Bullet', 'points' => [[2026, 6, 15, 1400]]],
            ['name' => 'Blitz', 'points' => [[2026, 6, 16, 1650], [2026, 5, 1, 1600], [2026, 6, 10, 1620], 'junk', [2026, 1, 30, 1]]],
            ['name' => 'Rapid', 'points' => [[2026, 4, 1, 1700]]],
        ]);

        $response = $this->api('/api/dashboard/lichess-rating-history?days=7', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertTrue($body['linked']);
        self::assertSame('alicel', $body['username']);
        self::assertSame([
            'blitz' => [['date' => '2026-07-14', 'rating' => 1620], ['date' => '2026-07-16', 'rating' => 1650]],
            'rapid' => [['date' => '2026-07-14', 'rating' => 1700]],
            'classical' => [],
        ], $body['perfs'], 'sorted, invalid points dropped, opened by the last rating before the period');
        self::assertCount(1, $this->calls);
        self::assertSame('https://lichess.org/api/user/alicel/rating-history', $this->calls[0]['url']);
        self::assertSame('Bearer lio_alice', $this->calls[0]['authorization']);

        self::assertSame(200, $this->api('/api/dashboard/lichess-rating-history?days=30', $alice)->getStatusCode());
        self::assertCount(1, $this->calls, 'served from the cache');
    }

    public function testARevokedTokenFallsBackToAnAnonymousRequest(): void
    {
        $alice = $this->createUser('alice@example.com', 'UTC');
        $this->linkLichess($alice, 'alicel', 'lio_revoked');
        $this->responses[] = new MockResponse('{"error":"No such token"}', ['http_code' => 401]);
        $this->responses[] = new JsonMockResponse([]);

        $response = $this->api('/api/dashboard/lichess-rating-history', $alice);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame([null], array_slice(array_column($this->calls, 'authorization'), 1));
    }

    public function testLichessRateLimitIsA503WithItsReason(): void
    {
        $alice = $this->createUser('alice@example.com', 'UTC');
        $this->linkLichess($alice, 'alicel', 'lio_alice');
        $this->responses[] = new MockResponse('', ['http_code' => 429]);

        $response = $this->api('/api/dashboard/lichess-rating-history', $alice);
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('rate_limited', $response->headers->get('X-Lichess-Unavailable'));

        self::assertSame(503, $this->api('/api/dashboard/lichess-rating-history', $alice)->getStatusCode());
        self::assertCount(1, $this->calls, 'paused: Lichess is not asked again');
    }

    public function testEndpointsNeedASignedInUserAndAreRateLimited(): void
    {
        foreach (['activity', 'rating-history', 'lichess-rating-history', 'training', 'themes', 'repertoire'] as $endpoint) {
            $this->client->request('GET', '/api/dashboard/'.$endpoint);
            self::assertSame(401, $this->client->getResponse()->getStatusCode(), $endpoint);
        }

        $alice = $this->createUser('alice@example.com', 'UTC');
        for ($i = 0; $i < 120; ++$i) {
            self::assertSame(200, $this->api('/api/dashboard/activity', $alice)->getStatusCode());
        }
        self::assertSame(429, $this->api('/api/dashboard/rating-history', $alice)->getStatusCode(), 'one budget for every dashboard read');
        self::assertSame(429, $this->api('/api/dashboard/themes', $alice)->getStatusCode());
    }

    private function createUser(string $email, string $timezone): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->markEmailVerified();
        $user->setTimezone($timezone);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function log(User $user, ExerciseType $type, bool $success, string $at, int $durationMs): void
    {
        $this->entityManager->persist(new LogEntry($user, new ExerciseCompleted(
            $user->getId()->toRfc4122(), $type, $success, $durationMs, 1, 'test', (string) ++$this->sequence, new \DateTimeImmutable($at, new \DateTimeZone('UTC')),
        )));
        $this->entityManager->flush();
    }

    private function rate(User $user, float $before, float $after, string $at): void
    {
        $this->entityManager->persist(new RatingChange(
            $user, RatingChangeReason::Attempt, new RatingState($before, 80, 0.06), new RatingState($after, 78, 0.06), new \DateTimeImmutable($at, new \DateTimeZone('UTC')),
        ));
        $this->entityManager->flush();
    }

    private function linkLichess(User $user, string $lichessId, string $token): void
    {
        $identity = new AuthIdentity($user, AuthProvider::Lichess, strtolower($lichessId));
        self::getContainer()->get(OAuthTokenVault::class)->store($identity, $token);
        $this->entityManager->persist($identity);
        $this->entityManager->flush();
        $this->entityManager->refresh($user);
    }

    private function api(string $uri, User $user): Response
    {
        $this->client->request('GET', $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);

        return $this->client->getResponse();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> $decoded */
        $decoded = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
