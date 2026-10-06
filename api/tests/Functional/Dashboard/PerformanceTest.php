<?php

declare(strict_types=1);

namespace App\Tests\Functional\Dashboard;

use App\Entity\User;
use App\Gamification\Xp\XpRebuilder;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * Performance of the dashboard statistics (docs/DASHBOARD.md § 6, lot B2) and of the gamification
 * (docs/GAMIFICATION.md): a year of a very
 * assiduous player, written in bulk SQL (rolled back afterwards like any test), then each endpoint
 * read over a year: median of 5 reads after a warm-up, at most 300 ms. Excluded from the default
 * run (phpunit.dist.xml); `vendor/bin/phpunit --group perf`.
 *
 * Per user: 36 500 rated puzzle attempts (100 a day), 65 000 activity entries, 365 sessions of 3
 * runs, 3 repertoires of 500 segments and 18 000 presentations. A second user as heavy shares the
 * tables. The repertoires have no moves, so no cards: the cards count is measured with the
 * repertoire statistics (docs/REPERTOIRE.md § 15).
 */
#[Group('perf')]
final class PerformanceTest extends WebTestCase
{
    private const NOW = '2026-07-20 10:00:00';
    private const BUDGET_MS = 300.0;
    private const PUZZLES = 40_000;
    private const ATTEMPTS = 36_500;
    private const ACTIVITY = 65_000;
    private const SESSIONS = 365;
    private const SEGMENTS = 500;
    private const PRESENTATIONS_PER_SEGMENT = 12;

    private KernelBrowser $client;
    private Connection $connection;
    /** The puzzle catalogue's database (docs/DEPLOY_OVH.md, § 3). */
    private Connection $catalog;
    /** JSON list of the seeded puzzles, {id, themes}, for the attempts. */
    private string $seededPuzzles = '[]';

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $container = self::getContainer();
        $this->connection = $container->get(Connection::class);
        $this->catalog = $container->get('doctrine.dbal.catalog_connection');
        $container->get('cache.rate_limiter')->clear();
        Clock::set(new MockClock(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testEveryStatisticReadsAYearInLessThan300Ms(): void
    {
        $started = microtime(true);
        $this->connection->executeStatement('SET SESSION cte_max_recursion_depth = 100000');
        $this->puzzles();
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        foreach ([$alice, $bob] as $user) {
            $this->attempts($user);
            $this->activity($user);
            $this->sessions($user);
            $this->repertoires($user);
            // The XP of that year, as after a deployment.
            self::getContainer()->get(XpRebuilder::class)->rebuild($user);
        }
        $report = \sprintf("\nDashboard, a year of history (seeded in %.1f s):\n", microtime(true) - $started);

        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($alice);
        $slow = [];
        foreach (['training', 'themes', 'repertoire', 'activity?days=371', 'rating-history?days=371', '/gamification/summary', '/gamification/trophies', '/gamification/quest'] as $endpoint) {
            $uri = str_starts_with($endpoint, '/') ? '/api'.$endpoint : '/api/dashboard/'.$endpoint.(str_contains($endpoint, '?') ? '' : '?days=371');
            $this->read($uri, $token); // warm-up
            $times = [];
            for ($i = 0; $i < 5; ++$i) {
                $start = hrtime(true);
                $this->read($uri, $token);
                $times[] = (hrtime(true) - $start) / 1e6;
            }
            sort($times);
            $report .= \sprintf("  %-40s median %6.1f ms, max %6.1f ms\n", $uri, $times[2], $times[4]);
            if ($times[2] > self::BUDGET_MS) {
                $slow[] = $uri;
            }
        }
        fwrite(\STDERR, $report);

        self::assertSame([], $slow, 'over 300 ms:'.$report);
    }

    private function read(string $uri, string $token): void
    {
        $this->client->request('GET', $uri, server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.$token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertSame(200, $this->client->getResponse()->getStatusCode(), $uri);
    }

    private function createUser(string $email): User
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $user = new User();
        $user->setEmail($email);
        $user->markEmailVerified();
        $entityManager->persist($user);
        $entityManager->flush();

        return $user;
    }

    /** Puzzles shared by both users, with a motif, a phase and a length among their themes. */
    private function puzzles(): void
    {
        $this->catalog->executeStatement(
            "INSERT INTO puzzle (lichess_id, fen, moves, rating, rating_deviation, popularity, nb_plays, themes, game_url, random_key, selectable)
             WITH RECURSIVE seq (n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM seq WHERE n < :last)
             SELECT LPAD(CONV(n + 1000000, 10, 36), 5, '0'), '8/8/8/8/8/8/8/K6k w - - 0 1', 'a1a2 h1h2', 800 + n % 1600, 80, 90, 1000,
                    JSON_ARRAY(
                        ELT(1 + n % 12, 'fork', 'pin', 'skewer', 'discoveredAttack', 'deflection', 'attraction', 'sacrifice', 'mateIn1', 'mateIn2', 'hangingPiece', 'trappedPiece', 'quietMove'),
                        ELT(1 + (n DIV 12) % 3, 'opening', 'middlegame', 'endgame'),
                        ELT(1 + n % 3, 'short', 'long', 'crushing')
                    ),
                    'https://perf.test', n, 1
               FROM seq",
            ['last' => self::PUZZLES - 1],
            ['last' => ParameterType::INTEGER],
        );
        // No join between the two databases: the attempts read the puzzles from this list.
        $seeded = $this->catalog->fetchOne(
            "SELECT JSON_ARRAYAGG(JSON_OBJECT('id', id, 'themes', themes)) FROM puzzle WHERE game_url = 'https://perf.test'",
        );
        $this->seededPuzzles = \is_string($seeded) ? $seeded : '[]';
    }

    /** 100 rated attempts a day, 30 % failed, some solved with a hint. */
    private function attempts(User $user): void
    {
        $this->connection->executeStatement(
            "INSERT INTO puzzle_attempt (id, rated, status, started_at, submitted_at, duration_ms, moves, mistakes, hint_level, solution_shown, user_id, puzzle_id, puzzle_themes)
             SELECT UUID_TO_BIN(UUID()), 1, IF(k % 10 < 3, 'failed', 'solved'),
                    TIMESTAMPADD(MINUTE, -(k DIV 365), TIMESTAMPADD(DAY, -(k % 365), :now)),
                    TIMESTAMPADD(SECOND, 20, TIMESTAMPADD(MINUTE, -(k DIV 365), TIMESTAMPADD(DAY, -(k % 365), :now))),
                    20000, '[\"a1a2\"]', IF(k % 10 < 3, 1, 0), IF(k % 10 >= 3 AND k % 17 = 0, 1, 0), 0, :user, id, themes
               FROM (SELECT p.id, p.themes, p.ordinal - 1 AS k
                       FROM JSON_TABLE(:puzzles, '$[*]' COLUMNS (ordinal FOR ORDINALITY, id INT UNSIGNED PATH '$.id', themes JSON PATH '$.themes')) p) p
              WHERE k < :count",
            ['now' => self::NOW, 'user' => $user->getId()->toBinary(), 'count' => self::ATTEMPTS, 'puzzles' => $this->seededPuzzles],
            ['count' => ParameterType::INTEGER],
        );
    }

    /** Exercises of every type over the year, 178 a day. */
    private function activity(User $user): void
    {
        $this->connection->executeStatement(
            "INSERT INTO activity_log_entry (id, exercise_type, success, duration_ms, item_count, source_type, source_id, occurred_at, local_date, timezone, metadata, user_id)
             WITH RECURSIVE seq (n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM seq WHERE n < :last)
             SELECT UUID_TO_BIN(UUID()),
                    ELT(1 + n % 10, 'puzzle_rated', 'puzzle_rated', 'puzzle_rated', 'puzzle_unrated', 'woodpecker_puzzle', 'woodpecker_puzzle',
                        'repertoire_segment', 'repertoire_segment', 'repertoire_segment', 'free_study'),
                    n % 3 > 0, 20000 + n % 40000, 1, 'perf', CONCAT(:tag, '-', n),
                    TIMESTAMPADD(MINUTE, -(n DIV 365), TIMESTAMPADD(DAY, -(n % 365), :now)),
                    DATE(TIMESTAMPADD(MINUTE, -(n DIV 365), TIMESTAMPADD(DAY, -(n % 365), :now))),
                    'UTC', '{}', :user
               FROM seq",
            ['last' => self::ACTIVITY - 1, 'tag' => $user->getId()->toRfc4122(), 'now' => self::NOW, 'user' => $user->getId()->toBinary()],
            ['last' => ParameterType::INTEGER],
        );
    }

    /** A closed session a day, of 3 runs of 5 minutes. */
    private function sessions(User $user): void
    {
        $params = ['last' => self::SESSIONS - 1, 'now' => self::NOW, 'user' => $user->getId()->toBinary()];
        $this->connection->executeStatement(
            "INSERT INTO training_session (id, title, description, steps, current_index, status, started_at, expires_at, closed_at, user_id)
             WITH RECURSIVE seq (n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM seq WHERE n < :last)
             SELECT UUID_TO_BIN(UUID()), 'Perf', '', '[]', 0, ELT(1 + n % 5, 'completed', 'completed', 'completed', 'abandoned', 'expired'),
                    TIMESTAMPADD(DAY, -(n + 1), :now), TIMESTAMPADD(DAY, -n, :now), TIMESTAMPADD(HOUR, 1, TIMESTAMPADD(DAY, -(n + 1), :now)), :user
               FROM seq",
            $params,
            ['last' => ParameterType::INTEGER],
        );
        $this->connection->executeStatement(
            "INSERT INTO training_run (id, module, subject_type, subject_id, config, budget_seconds, status, started_at, expires_at, closed_at, close_reason, summary, parent_id, user_id)
             SELECT UUID_TO_BIN(UUID()), 'free', 'user', :user, '{}', 600, 'closed',
                    TIMESTAMPADD(MINUTE, 10 * k.n, s.started_at), TIMESTAMPADD(MINUTE, 10 * k.n + 10, s.started_at), TIMESTAMPADD(MINUTE, 10 * k.n + 5, s.started_at),
                    'time_up', JSON_OBJECT('durationMs', 300000), s.id, :user
               FROM training_session s CROSS JOIN (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2) k
              WHERE s.user_id = :user",
            ['user' => $user->getId()->toBinary()],
        );
    }

    /** 3 repertoires of 500 segments (no moves), each segment tested 12 times over the year. */
    private function repertoires(User $user): void
    {
        $params = ['now' => self::NOW, 'user' => $user->getId()->toBinary()];
        $this->connection->executeStatement(
            "INSERT INTO repertoire (id, name, color, position_count, version, created_at, updated_at, user_id)
             SELECT UUID_TO_BIN(UUID()), CONCAT('Perf ', k.n), IF(k.n = 1, 'black', 'white'), 1, 0, :now, :now, :user
               FROM (SELECT 0 AS n UNION ALL SELECT 1 UNION ALL SELECT 2) k",
            $params,
        );
        // A start move id on each segment: none is the trunk (one per repertoire at most).
        $this->connection->executeStatement(
            'INSERT INTO repertoire_segment (id, start_move_id, move_count, user_move_count, created_at, repertoire_id)
             WITH RECURSIVE seq (n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM seq WHERE n < :last)
             SELECT UUID_TO_BIN(UUID()), UUID_TO_BIN(UUID()), 6, 3, :now, r.id
               FROM seq CROSS JOIN repertoire r
              WHERE r.user_id = :user',
            [...$params, 'last' => self::SEGMENTS - 1],
            ['last' => ParameterType::INTEGER],
        );
        $this->connection->executeStatement(
            "INSERT INTO repertoire_presentation (id, unit_id, unit, presentation_rank, round, status, positions_graded, moves, label, started_at, finished_at, duration_ms, user_id, repertoire_id, segment_id)
             WITH RECURSIVE seq (n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM seq WHERE n < :last)
             SELECT UUID_TO_BIN(UUID()), UUID_TO_BIN(UUID()), 'segment', 1, 1, IF((s.k + j.n) % 7 = 0, 'failed', 'succeeded'), 3, '[\"e4\"]',
                    JSON_OBJECT('opening', NULL, 'move', '1.e4'),
                    TIMESTAMPADD(SECOND, -30, TIMESTAMPADD(DAY, -((s.k * 12 + j.n) % 365), :now)),
                    TIMESTAMPADD(DAY, -((s.k * 12 + j.n) % 365), :now), 30000, :user, s.repertoire_id, s.id
               FROM (
                    SELECT id, repertoire_id, ROW_NUMBER() OVER (ORDER BY id) - 1 AS k
                      FROM repertoire_segment
                     WHERE repertoire_id IN (SELECT id FROM repertoire WHERE user_id = :user)
               ) s CROSS JOIN seq j",
            [...$params, 'last' => self::PRESENTATIONS_PER_SEGMENT - 1],
            ['last' => ParameterType::INTEGER],
        );
    }
}
