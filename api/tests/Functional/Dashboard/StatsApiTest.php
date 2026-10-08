<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\Activity\LogEntry;
use App\Entity\Catalog\Puzzle;
use App\Entity\Puzzle\Attempt;
use App\Entity\Training\Run;
use App\Entity\Training\Session;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Training\CloseReason;
use App\Enum\Training\Module;
use App\Enum\Training\SessionStatus;
use App\Puzzle\Theme\ThemeSynchronizer;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Statistics of the dashboard (docs/DASHBOARD.md): training time by week and module, sessions,
 * strong and weak puzzle themes. Today is Monday 2026-07-20; over 7 days in Paris the period starts
 * on Tuesday 2026-07-14 (2026-07-13 22:00 UTC).
 */
final class StatsApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->entityManager = $container->get(EntityManagerInterface::class);
        $container->get('cache.rate_limiter')->clear();
        Clock::set(new MockClock('2026-07-20 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTrainingTimeIsSplitByLocalWeekAndModule(): void
    {
        $alice = $this->createUser('alice@example.com', 'Europe/Paris');
        $bob = $this->createUser('bob@example.com', 'Europe/Paris');
        $this->log($alice, ExerciseType::PuzzleRated, '2026-07-13 21:30:00', 30_000); // 07-13 23:30 Paris: before the period
        $this->log($alice, ExerciseType::PuzzleRated, '2026-07-13 22:30:00', 20_000); // 07-14 00:30 Paris
        $this->log($alice, ExerciseType::PuzzleUnrated, '2026-07-15 08:00:00', 10_000);
        $this->log($alice, ExerciseType::WoodpeckerPuzzle, '2026-07-19 21:59:00', 15_000); // Sunday 23:59 Paris
        $this->log($alice, ExerciseType::FreeStudy, '2026-07-20 08:00:00', 600_000);
        $this->log($alice, ExerciseType::RepertoireSegment, '2026-07-20 09:00:00', 5_000);
        $this->log($alice, ExerciseType::BlindfoldPuzzle, '2026-07-16 08:00:00', 8_000);
        $this->log($alice, ExerciseType::CoordinatesSeries, '2026-07-20 09:30:00', 300_000);
        $this->log($bob, ExerciseType::FreeStudy, '2026-07-20 08:00:00', 900_000);

        $response = $this->api('/api/dashboard/training?days=7', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame(['2026-07-14', '2026-07-20'], [$body['from'], $body['today']]);
        self::assertSame([
            ['start' => '2026-07-13', 'durationMs' => ['woodpecker' => 15_000, 'repertoire' => 0, 'puzzles' => 30_000, 'free' => 0, 'coordinates' => 0, 'blindfold' => 8_000, 'evaluation' => 0]],
            ['start' => '2026-07-20', 'durationMs' => ['woodpecker' => 0, 'repertoire' => 5_000, 'puzzles' => 0, 'free' => 600_000, 'coordinates' => 300_000, 'blindfold' => 0, 'evaluation' => 0]],
        ], $body['weeks'], 'weeks from Monday, the first one partial');
        self::assertSame([
            'woodpecker' => ['count' => 1, 'durationMs' => 15_000],
            'repertoire' => ['count' => 1, 'durationMs' => 5_000],
            'puzzles' => ['count' => 2, 'durationMs' => 30_000],
            'free' => ['count' => 1, 'durationMs' => 600_000],
            'coordinates' => ['count' => 1, 'durationMs' => 300_000],
            'blindfold' => ['count' => 1, 'durationMs' => 8_000],
            'evaluation' => ['count' => 0, 'durationMs' => 0],
        ], $body['totals'], 'rated and unrated puzzles together, Bob excluded');

        $body = $this->json($this->api('/api/dashboard/training', $this->createUser('new@example.com', 'UTC')));
        self::assertSame('2026-06-21', $body['from'], '30 days by default');
        self::assertIsArray($body['weeks']);
        self::assertSame(['2026-06-15', '2026-06-22', '2026-06-29', '2026-07-06', '2026-07-13', '2026-07-20'], array_column($body['weeks'], 'start'), 'empty weeks listed');
        self::assertIsArray($body['totals']);
        self::assertSame(['count' => 0, 'durationMs' => 0], $body['totals']['puzzles'] ?? null);
        self::assertSame(['closed' => 0, 'completed' => 0, 'abandoned' => 0, 'expired' => 0, 'playedMs' => 0, 'averageMs' => null], $body['sessions']);
    }

    public function testSessionsOfThePeriodCountTheirRunsTime(): void
    {
        $alice = $this->createUser('alice@example.com', 'Europe/Paris');
        $bob = $this->createUser('bob@example.com', 'Europe/Paris');
        $this->session($alice, '2026-07-15 08:00:00', SessionStatus::Completed, [300_000, 200_000]);
        $this->session($alice, '2026-07-16 08:00:00', SessionStatus::Expired, []); // nothing played
        $this->session($alice, '2026-07-17 08:00:00', SessionStatus::Abandoned, [120_000]);
        $this->session($alice, '2026-07-13 21:00:00', SessionStatus::Completed, [60_000]); // 07-13 Paris: before the period
        $this->session($alice, '2026-07-20 09:00:00', null, [60_000]); // still active
        $this->session($bob, '2026-07-15 08:00:00', SessionStatus::Completed, [999_000]);

        $body = $this->json($this->api('/api/dashboard/training?days=7', $alice));
        self::assertSame(
            ['closed' => 3, 'completed' => 1, 'abandoned' => 1, 'expired' => 1, 'playedMs' => 620_000, 'averageMs' => 310_000],
            $body['sessions'],
            'the average leaves out the session where nothing was played',
        );
    }

    public function testThemesCountRatedPuzzlesSolvedWithoutHelp(): void
    {
        self::getContainer()->get(ThemeSynchronizer::class)->sync();
        $alice = $this->createUser('alice@example.com', 'Europe/Paris');
        $bob = $this->createUser('bob@example.com', 'Europe/Paris');
        $at = '2026-07-15 08:00:00';

        // Fork: 5 of 6 (short and crushing are a length and a goal: left out).
        $forks = $this->puzzles(6, ['fork', 'short', 'crushing']);
        foreach ($forks as $i => $puzzle) {
            $this->attempt($alice, $puzzle, true, 5 === $i ? 'failed' : 'clean', $at);
        }
        $this->attempt($alice, $forks[0], false, 'failed', $at); // unrated: left out
        $this->attempt($bob, $forks[1], true, 'failed', $at);
        // Pin: 1 of 5, the one solved with a hint is a failure.
        foreach ($this->puzzles(5, ['pin', 'short']) as $i => $puzzle) {
            $this->attempt($alice, $puzzle, true, ['clean', 'hint', 'failed', 'failed', 'failed'][$i], $at);
        }
        $this->attempt($alice, $this->puzzles(1, ['pin'])[0], true, 'failed', '2026-07-13 21:00:00'); // before the period
        $this->attempt($alice, $this->puzzles(1, ['pin'])[0], true, null, $at); // pending
        // Endgame: 3 of 5; mate in 2: only 4 attempts, not listed.
        foreach ($this->puzzles(5, ['endgame']) as $i => $puzzle) {
            $this->attempt($alice, $puzzle, true, $i < 3 ? 'clean' : 'failed', $at);
        }
        foreach ($this->puzzles(4, ['mateIn2']) as $i => $puzzle) {
            $this->attempt($alice, $puzzle, true, $i < 2 ? 'clean' : 'failed', $at);
        }

        $response = $this->api('/api/dashboard/themes?days=7', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $body = $this->json($response);
        self::assertSame([20, 11, 5], [$body['attempts'], $body['successCount'], $body['minAttempts']]);
        $fork = ['key' => 'fork', 'attempts' => 6, 'successCount' => 5, 'successRate' => 0.8333];
        $endgame = ['key' => 'endgame', 'attempts' => 5, 'successCount' => 3, 'successRate' => 0.6];
        $pin = ['key' => 'pin', 'attempts' => 5, 'successCount' => 1, 'successRate' => 0.2];
        self::assertEquals([$fork, $endgame, $pin], $body['themes']);
        self::assertEquals([$fork, $endgame], $body['strong']);
        self::assertEquals([$pin], $body['weak'], 'never the same theme strong and weak');

        $body = $this->json($this->api('/api/dashboard/themes', $bob));
        self::assertSame([1, 0, [], [], []], [$body['attempts'], $body['successCount'], $body['themes'], $body['strong'], $body['weak']]);
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

    private function log(User $user, ExerciseType $type, string $at, int $durationMs): void
    {
        $this->entityManager->persist(new LogEntry($user, new ExerciseCompleted(
            $user->getId()->toRfc4122(), $type, true, $durationMs, 1, 'test', (string) ++$this->sequence, self::utc($at),
        )));
        $this->entityManager->flush();
    }

    /**
     * A session with one run per duration, closed with $status (null: still active).
     *
     * @param list<int> $runs durationMs of each run
     */
    private function session(User $user, string $at, ?SessionStatus $status, array $runs): void
    {
        $startedAt = self::utc($at);
        $session = new Session($user, 'Séance', '', [['module' => Module::Free, 'minutes' => 10, 'notes' => '', 'settings' => []]], $startedAt, $startedAt->modify('+1 day'));
        foreach ($runs as $durationMs) {
            $run = new Run($user, Module::Free, 'user', $user->getId(), 600, [], $startedAt, $session->getId());
            $run->close(CloseReason::TimeUp, $startedAt->modify('+10 minutes'), ['durationMs' => $durationMs]);
            $this->entityManager->persist($run);
        }
        if (null !== $status) {
            $session->close($status, $startedAt->modify('+1 hour'));
        }
        $this->entityManager->persist($session);
        $this->entityManager->flush();
    }

    /**
     * @param list<string> $themes
     *
     * @return list<Puzzle>
     */
    private function puzzles(int $count, array $themes): array
    {
        $catalog = self::getContainer()->get('doctrine.orm.catalog_entity_manager');
        $puzzles = [];
        for ($i = 0; $i < $count; ++$i) {
            $puzzle = new Puzzle(\sprintf('t%04d', ++$this->sequence), '8/8/8/8/8/8/8/K6k w - - 0 1', 'a1a2 h1h2', 1500, 80, 90, 1000, $themes, 'https://lichess.org/test');
            $catalog->persist($puzzle);
            $puzzles[] = $puzzle;
        }
        $catalog->flush();

        return $puzzles;
    }

    /**
     * @param 'clean'|'hint'|'failed'|null $outcome null: still pending
     */
    private function attempt(User $user, Puzzle $puzzle, bool $rated, ?string $outcome, string $at): void
    {
        $attempt = new Attempt($user, $puzzle, $rated, self::utc($at));
        if (null !== $outcome) {
            $attempt->resolve('failed' !== $outcome, ['a1a2'], 'failed' === $outcome ? 1 : 0, 'hint' === $outcome ? 1 : 0, false, self::utc($at)->modify('+20 seconds'), null);
        }
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();
    }

    private static function utc(string $at): \DateTimeImmutable
    {
        return new \DateTimeImmutable($at, new \DateTimeZone('UTC'));
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
