<?php

declare(strict_types=1);

namespace App\Tests\Functional\Gamification;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\Activity\LogEntry;
use App\Entity\Catalog\Puzzle;
use App\Entity\Puzzle\Attempt;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * The weekly quest (docs/GAMIFICATION.md): drawn on the first read, its goal from the 4 weeks
 * before, completed once at the instant of the feat with its reward as XP, a past week settled
 * later. Monday 2026-10-05 is the first day of the week in Paris.
 *
 * @phpstan-type QuestJson array{id: string, template: string, theme: string|null, module: string|null, goal: int, current: int, reward: int, completed: bool, completedAt: string|null, weekStart: string, weekEnd: string}
 */
final class QuestTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private MockClock $clock;
    private int $sequence = 0;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::getContainer()->get('cache.rate_limiter')->clear();
        $this->clock = new MockClock('2026-10-05 10:00:00', 'UTC');
        Clock::set($this->clock);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testANewPlayerTrainsThreeDaysAndAPastWeekIsSettledLater(): void
    {
        $alice = $this->createUser();
        $quest = $this->quest($alice);
        self::assertSame(['active_days', 3, 0, 150, false, null, '2026-10-05', '2026-10-11'], [
            $quest['template'], $quest['goal'], $quest['current'], $quest['reward'], $quest['completed'], $quest['module'], $quest['weekStart'], $quest['weekEnd'],
        ]);
        self::assertSame($quest['id'], $this->quest($alice)['id'], 'the same all week');

        // Three days of training, the quest is not read again that week.
        foreach (['2026-10-05 08:00:00', '2026-10-06 08:00:00', '2026-10-06 09:00:00', '2026-10-07 08:00:00'] as $at) {
            $this->log($alice, $at);
        }
        $this->clock->modify('+8 days'); // Tuesday 13 October
        $next = $this->quest($alice);
        self::assertSame(['2026-10-12', 0, false], [$next['weekStart'], $next['current'], $next['completed']]);
        self::assertNotSame($quest['id'], $next['id']);

        $settled = $this->connection()->fetchOne('SELECT completed_at FROM gamification_quest WHERE id = ?', [Uuid::fromString($quest['id'])->toBinary()]);
        self::assertSame('2026-10-07 08:00:00', $settled, 'the first exercise of the third day');
        self::assertSame(150, $this->questXp($alice));
        $this->quest($alice);
        self::assertSame(150, $this->questXp($alice), 'gained once');
    }

    public function testTheGoalFollowsTheFourWeeksBeforeAndCompletesWithTheFeat(): void
    {
        $alice = $this->createUser();
        // 160 rated puzzles solved in the 4 weeks before (40 a week): 44, rounded to 45.
        for ($i = 0; $i < 160; ++$i) {
            $this->solve($alice, (new \DateTimeImmutable('2026-09-08 08:00:00'))->modify(\sprintf('+%d hours', 4 * $i)));
        }
        // Last week's quest was about training days: not drawn twice in a row.
        $this->connection()->executeStatement(
            "INSERT INTO gamification_quest (id, user_id, week_start, template, theme, goal, reward, created_at)
             VALUES (?, ?, '2026-09-28', 'active_days', NULL, 3, 150, '2026-09-28 08:00:00')",
            [Uuid::v7()->toBinary(), $alice->getId()->toBinary()],
        );

        $quest = $this->quest($alice);
        self::assertSame(['rated_puzzles', 45, 'puzzles', 0], [$quest['template'], $quest['goal'], $quest['module'], $quest['current']]);

        for ($i = 0; $i < 45; ++$i) {
            $this->solve($alice, (new \DateTimeImmutable('2026-10-05 08:00:00'))->modify(\sprintf('+%d minutes', $i)));
        }
        $quest = $this->quest($alice);
        self::assertSame([true, 45, '2026-10-05T08:44:20+00:00'], [$quest['completed'], $quest['current'], $quest['completedAt']], 'the 45th puzzle');
        self::assertSame(150, $this->questXp($alice));
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
        $user = $this->managed($user);
        $this->entityManager->persist(new LogEntry($user, new ExerciseCompleted(
            $user->getId()->toRfc4122(), ExerciseType::PuzzleRated, true, 20_000, 1, 'test', (string) ++$this->sequence, new \DateTimeImmutable($at, new \DateTimeZone('UTC')),
        )));
        $this->entityManager->flush();
    }

    /** A rated puzzle solved (submitted 20 s after $at). */
    private function solve(User $user, \DateTimeImmutable $at): void
    {
        $user = $this->managed($user);
        $puzzle = new Puzzle(\sprintf('q%04d', ++$this->sequence), '8/8/8/8/8/8/8/K6k w - - 0 1', 'a1a2 h1h2', 1500, 80, 90, 1000, ['fork'], 'https://lichess.org/test');
        $catalog = self::getContainer()->get('doctrine.orm.catalog_entity_manager');
        $catalog->persist($puzzle);
        $catalog->flush();
        $attempt = new Attempt($user, $puzzle, true, $at);
        $attempt->resolve(true, ['h1h2'], 0, 0, false, $at->modify('+20 seconds'), null);
        $this->entityManager->persist($attempt);
        $this->entityManager->flush();
    }

    /**
     * The user as the entity manager knows it now: the test client resets the services between
     * requests, which empties the entity manager.
     */
    private function managed(User $user): User
    {
        $managed = $this->entityManager->find(User::class, $user->getId());
        self::assertInstanceOf(User::class, $managed);

        return $managed;
    }

    /**
     * @return QuestJson
     */
    private function quest(User $user): array
    {
        $this->client->request('GET', '/api/gamification/quest', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var QuestJson $body */
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $body;
    }

    private function questXp(User $user): int
    {
        $sum = $this->connection()->fetchOne("SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'quest'", [$user->getId()->toBinary()]);
        self::assertTrue(is_numeric($sum));

        return (int) $sum;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
