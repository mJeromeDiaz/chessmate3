<?php

declare(strict_types=1);

namespace App\Tests\Functional\Gamification;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\Activity\LogEntry;
use App\Entity\Catalog\Puzzle;
use App\Entity\Puzzle\Attempt;
use App\Entity\Training\Session;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Repertoire\Color;
use App\Enum\Training\Module;
use App\Enum\Training\SessionStatus;
use App\Repertoire\RepertoireManager;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Uid\Uuid;

/**
 * Trophies (docs/GAMIFICATION.md): progress of the locked ones, the date of the feat for the won
 * ones, stored once, rebuilt identical. Today is Monday 2026-10-05 in Paris.
 *
 * @phpstan-type View array{key: string, goal: int, current: int, unlocked: bool, unlockedAt: string|null, ratio: float|int|null}
 */
final class TrophyTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get('cache.rate_limiter')->clear();
        Clock::set(new MockClock('2026-10-05 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testWonTrophiesKeepTheDateOfTheFeatAndLockedOnesTheirProgress(): void
    {
        $alice = $this->createUser();
        // Seven days in a row, from Sunday 27 September to Saturday 3 October.
        foreach (range(27, 30) as $day) {
            $this->log($alice, \sprintf('2026-09-%02d 08:00:00', $day));
        }
        foreach ([1, 2, 3] as $day) {
            $this->log($alice, \sprintf('2026-10-%02d 08:00:00', $day));
            $this->log($alice, \sprintf('2026-10-%02d 18:00:00', $day));
        }
        // Three forks solved without help, one with a hint.
        $catalog = self::getContainer()->get('doctrine.orm.catalog_entity_manager');
        foreach (['clean', 'clean', 'clean', 'hint'] as $i => $outcome) {
            $puzzle = new Puzzle(\sprintf('f%04d', $i), '8/8/8/8/8/8/8/K6k w - - 0 1', 'a1a2 h1h2', 1500, 80, 90, 1000, ['fork', 'short'], 'https://lichess.org/test');
            $catalog->persist($puzzle);
            $catalog->flush();
            $attempt = new Attempt($alice, $puzzle, true, new \DateTimeImmutable('2026-10-02 08:00:00'));
            $attempt->resolve(true, ['h1h2'], 0, 'hint' === $outcome ? 1 : 0, false, new \DateTimeImmutable('2026-10-02 08:00:20'), null);
            $this->entityManager->persist($attempt);
        }
        foreach ([1, 2] as $i) {
            $at = new \DateTimeImmutable(\sprintf('2026-10-0%d 07:00:00', $i));
            $session = new Session($alice, 'Matinale', '', [['module' => Module::Free, 'minutes' => 10, 'notes' => '', 'settings' => []]], $at, $at->modify('+1 day'));
            $session->close(SessionStatus::Completed, $at->modify('+20 minutes'));
            $this->entityManager->persist($session);
        }
        $this->entityManager->flush();
        $lastTest = $this->repertoireTests($alice);

        $trophies = $this->trophies($alice);
        self::assertSame(['on_fire', 'woodpecker', 'golden_fork', 'iron_memory', 'first_step', 'unstoppable', 'steel_woodpecker', 'centurion', 'conductor', 'marathon'], array_column($trophies, 'key'));
        $byKey = array_column($trophies, null, 'key');
        self::assertSame([true, '2026-10-03T08:00:00+00:00'], [$byKey['on_fire']['unlocked'], $byKey['on_fire']['unlockedAt']], 'the first exercise of the 7th day');
        self::assertSame('2026-09-27T08:00:00+00:00', $byKey['first_step']['unlockedAt']);
        self::assertSame([true, $lastTest], [$byKey['iron_memory']['unlocked'], $byKey['iron_memory']['unlockedAt']], '48 of 50 tests in 30 days');
        self::assertSame([3, 100, false], [$byKey['golden_fork']['current'], $byKey['golden_fork']['goal'], $byKey['golden_fork']['unlocked']], 'the hinted one does not count');
        self::assertSame([4, 1000], [$byKey['centurion']['current'], $byKey['centurion']['goal']], 'every solved puzzle');
        self::assertSame([7, 30], [$byKey['unstoppable']['current'], $byKey['unstoppable']['goal']]);
        self::assertSame(2, $byKey['conductor']['current']);
        self::assertSame([0, false], [$byKey['woodpecker']['current'], $byKey['woodpecker']['unlocked']]);
        self::assertSame(0, $byKey['marathon']['current']);
        self::assertSame(3, $this->stored($alice));

        // Read again: the same dates, nothing more stored.
        $again = array_column($this->trophies($alice), null, 'key');
        self::assertSame($byKey['on_fire']['unlockedAt'], $again['on_fire']['unlockedAt']);
        self::assertSame(3, $this->stored($alice));

        // Rebuilt: the same trophies at the same dates.
        $command = new CommandTester((new Application($this->client->getKernel()))->find('app:gamification:rebuild'));
        self::assertSame(0, $command->execute(['--user' => $alice->getId()->toRfc4122()]));
        self::assertStringContainsString('3 trophies', $command->getDisplay());
        $rebuilt = array_column($this->trophies($alice), null, 'key');
        self::assertSame($byKey['iron_memory']['unlockedAt'], $rebuilt['iron_memory']['unlockedAt']);
    }

    private function createUser(): User
    {
        $user = new User();
        $user->setEmail('alice@example.com');
        $user->markEmailVerified();
        $user->setTimezone('Europe/Paris');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function log(User $user, string $at): void
    {
        $this->entityManager->persist(new LogEntry($user, new ExerciseCompleted(
            $user->getId()->toRfc4122(), ExerciseType::PuzzleRated, true, 20_000, 1, 'test', (string) ++$this->sequence, new \DateTimeImmutable($at, new \DateTimeZone('UTC')),
        )));
        $this->entityManager->flush();
    }

    /**
     * Repertoire tests: five failed two months ago (out of the window), then 50 in September,
     * two of them failed.
     *
     * @return string when the 50th recent test finished (ISO 8601)
     */
    private function repertoireTests(User $user): string
    {
        $repertoire = self::getContainer()->get(RepertoireManager::class)->create($user, 'Italienne', Color::White);
        $connection = $this->connection();
        $segment = Uuid::v7();
        $connection->executeStatement(
            'INSERT INTO repertoire_segment (id, start_move_id, move_count, user_move_count, created_at, repertoire_id) VALUES (?, ?, 2, 1, UTC_TIMESTAMP(), ?)',
            [$segment->toBinary(), Uuid::v7()->toBinary(), $repertoire->getId()->toBinary()],
        );
        $tests = [];
        for ($i = 0; $i < 5; ++$i) {
            $tests[] = [new \DateTimeImmutable(\sprintf('2026-07-20 08:%02d:00', $i)), 'failed'];
        }
        for ($i = 0; $i < 50; ++$i) {
            $tests[] = [(new \DateTimeImmutable('2026-09-10 08:00:00'))->modify(\sprintf('+%d hours', $i)), \in_array($i, [10, 20], true) ? 'failed' : 'succeeded'];
        }
        foreach ($tests as [$at, $status]) {
            $connection->executeStatement(
                "INSERT INTO repertoire_presentation (id, unit_id, unit, presentation_rank, round, status, positions_graded, moves, label, started_at, finished_at, duration_ms, user_id, repertoire_id, segment_id)
                 VALUES (?, ?, 'segment', 1, 1, ?, 1, '[]', '{}', ?, ?, 1000, ?, ?, ?)",
                [Uuid::v7()->toBinary(), Uuid::v7()->toBinary(), $status, $at->format('Y-m-d H:i:s'), $at->format('Y-m-d H:i:s'), $user->getId()->toBinary(), $repertoire->getId()->toBinary(), $segment->toBinary()],
            );
        }

        return $tests[54][0]->format(\DATE_ATOM);
    }

    /**
     * @return list<View>
     */
    private function trophies(User $user): array
    {
        $this->client->request('GET', '/api/gamification/trophies', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{trophies: list<View>} $body */
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $body['trophies'];
    }

    private function stored(User $user): int
    {
        $count = $this->connection()->fetchOne('SELECT COUNT(*) FROM gamification_trophy WHERE user_id = ?', [$user->getId()->toBinary()]);
        self::assertTrue(is_numeric($count));

        return (int) $count;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
