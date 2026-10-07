<?php

declare(strict_types=1);

namespace App\Tests\Functional\Gamification;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\Training\Session;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Enum\Training\Module;
use App\Enum\Training\SessionStatus;
use App\Training\Event\SessionClosed;
use App\Woodpecker\Event\CycleCompleted;
use App\Woodpecker\Event\SetCompleted;
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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * XP (docs/GAMIFICATION.md): the handlers of the domain events (idempotent, daily cap, bonuses),
 * the summary, and the rebuild from what was played. Today is Monday 2026-10-05 in Paris.
 */
final class XpTest extends WebTestCase
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

    public function testExercisesGainXpOnceWithinTheDailyCap(): void
    {
        $alice = $this->createUser('alice@example.com');
        $event = $this->exercise($alice, ExerciseType::PuzzleRated, true, '2026-10-05 08:00:00');
        $this->deliver($event);
        $this->deliver($event); // redelivered
        $this->deliver($this->exercise($alice, ExerciseType::RepertoireSegment, false, '2026-10-05 08:01:00'));
        self::assertSame(14, $this->xpOf($alice));

        // 60 more solved puzzles the same day: 600 XP asked, 500 in all at most.
        for ($i = 0; $i < 60; ++$i) {
            $this->deliver($this->exercise($alice, ExerciseType::PuzzleRated, true, '2026-10-05 09:00:00'));
        }
        self::assertSame(500, $this->xpOf($alice));
        // The next local day starts afresh (22:30 UTC is already the 6th in Paris).
        $this->deliver($this->exercise($alice, ExerciseType::PuzzleRated, true, '2026-10-05 22:30:00'));
        self::assertSame(510, $this->xpOf($alice));
    }

    public function testBonusesForSessionsCyclesAndSets(): void
    {
        $alice = $this->createUser('alice@example.com');
        $id = $alice->getId()->toRfc4122();
        $at = new \DateTimeImmutable('2026-10-05 08:00:00');
        $this->deliver(new SessionClosed($id, '0192f0c4-0000-7000-8000-000000000001', SessionStatus::Completed->value, 3, 3, 0, 600_000, $at, $at));
        $this->deliver(new SessionClosed($id, '0192f0c4-0000-7000-8000-000000000002', SessionStatus::Abandoned->value, 3, 1, 0, 200_000, $at, $at));
        $cycle = new CycleCompleted($id, '0192f0c4-0000-7000-8000-000000000003', 1, 1, 7, 300, 280, 20, 3_600_000, 86_400_000, $at, $at);
        $this->deliver($cycle);
        $this->deliver($cycle);
        $this->deliver(new SetCompleted($id, '0192f0c4-0000-7000-8000-000000000003', 7, 300, 0, $at));

        self::assertSame(50 + 100 + 300, $this->xpOf($alice));
    }

    public function testTheSummaryGivesTheLevelModulesAndStreak(): void
    {
        $alice = $this->createUser('alice@example.com');
        foreach (['2026-10-01 08:00:00', '2026-10-03 08:00:00', '2026-10-04 08:00:00', '2026-10-05 08:00:00'] as $at) {
            $this->deliver($this->exercise($alice, ExerciseType::PuzzleRated, true, $at));
        }
        $this->deliver($this->exercise($alice, ExerciseType::WoodpeckerPuzzle, true, '2026-10-05 08:05:00'));

        $this->client->request('GET', '/api/gamification/summary', server: [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($alice),
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame([48, 1, 48, 250, 'Débutant'], [$body['xp'], $body['level'], $body['xpInLevel'], $body['xpForNext'], $body['rank']]);
        self::assertSame(['rank' => 'Amateur', 'level' => 5], $body['nextRank']);
        $modules = $body['modules'];
        self::assertIsArray($modules);
        self::assertSame(['xp' => 40, 'level' => 1, 'xpInLevel' => 40, 'xpForNext' => 100], $modules['puzzles']);
        $woodpecker = $modules['woodpecker'] ?? null;
        self::assertIsArray($woodpecker);
        self::assertSame(8, $woodpecker['xp'] ?? null);
        // Monday: Thursday to Sunday belong to the past week.
        self::assertSame(['current' => 3, 'best' => 3, 'playedToday' => true, 'week' => [true, false, false, false, false, false, false], 'nextMilestone' => 7], $body['streak']);
        self::assertSame(['date' => '2026-10-05', 'exerciseXp' => 18, 'cap' => 500], $body['today']);

        $this->client->request('GET', '/api/gamification/summary');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    public function testTheRebuildFindsTheSameXpFromWhatWasPlayed(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->deliver($this->exercise($alice, ExerciseType::PuzzleRated, true, '2026-10-04 08:00:00'));
        $this->deliver($this->exercise($alice, ExerciseType::FreeStudy, true, '2026-10-05 08:00:00', 30 * 60_000));
        $at = new \DateTimeImmutable('2026-10-05 07:00:00');
        $session = new Session($alice, 'Matinale', '', [['module' => Module::Free, 'minutes' => 30, 'notes' => '', 'settings' => []]], $at, $at->modify('+1 day'));
        $session->close(SessionStatus::Completed, $at->modify('+40 minutes'));
        $this->entityManager->persist($session);
        $this->entityManager->flush();
        $this->deliver(new SessionClosed($alice->getId()->toRfc4122(), $session->getId()->toRfc4122(), SessionStatus::Completed->value, 1, 1, 0, 1_800_000, $at, $at->modify('+40 minutes')));
        $handled = $this->xpOf($alice);
        self::assertSame(10 + 30 + 50, $handled);

        $this->connection()->executeStatement('DELETE FROM gamification_xp_entry');
        $command = new CommandTester((new Application($this->client->getKernel()))->find('app:gamification:rebuild'));
        self::assertSame(0, $command->execute(['--user' => $alice->getId()->toRfc4122()]));
        self::assertSame($handled, $this->xpOf($alice));
        self::assertSame(0, $command->execute([]), 'every user, idempotent');
        self::assertSame($handled, $this->xpOf($alice));
        self::assertSame(1, $command->execute(['--user' => 'nobody']));
    }

    private function createUser(string $email): User
    {
        $user = new User();
        $user->setEmail($email);
        $user->markEmailVerified();
        $user->setTimezone('Europe/Paris');
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function exercise(User $user, ExerciseType $type, bool $success, string $at, int $durationMs = 20_000): ExerciseCompleted
    {
        return new ExerciseCompleted(
            $user->getId()->toRfc4122(), $type, $success, $durationMs, 1, 'test', (string) ++$this->sequence, new \DateTimeImmutable($at, new \DateTimeZone('UTC')),
        );
    }

    /** Runs every handler of an event, as the worker does. */
    private function deliver(object $event): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope($event, [new ReceivedStamp('activity')]));
    }

    private function xpOf(User $user): int
    {
        $sum = $this->connection()->fetchOne('SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ?', [$user->getId()->toBinary()]);
        self::assertTrue(is_numeric($sum));

        return (int) $sum;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
