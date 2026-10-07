<?php

declare(strict_types=1);

namespace App\Tests\Functional\Gamification;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
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
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;

/**
 * The streak announcement (docs/GAMIFICATION.md, "Annonce de la série"): written when the first
 * exercise of the local day enters the outbox, shown once, never for a past day. Today is Monday
 * 2026-10-05 in Paris.
 */
final class StreakNoticeTest extends WebTestCase
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

    public function testTheFirstExerciseOfTheDayAnnouncesTheStreakOnce(): void
    {
        $alice = $this->createUser('alice@example.com');
        foreach (['2026-10-02', '2026-10-03', '2026-10-04'] as $day) {
            $this->deliver($this->exercise($alice, $day.' 08:00:00'));
        }
        self::assertSame(['pending' => false, 'streak' => null, 'previousStreak' => null, 'badge' => null, 'week' => null, 'nextMilestone' => null, 'localDate' => null], $this->notice($alice));

        $this->publish($this->exercise($alice, '2026-10-05 09:00:00'));
        $expected = [
            'pending' => true, 'streak' => 4, 'previousStreak' => 3, 'badge' => null,
            // Monday, today's exercise still in the outbox: Friday to Sunday belong to the past week.
            'week' => [true, false, false, false, false, false, false], 'nextMilestone' => 7, 'localDate' => '2026-10-05',
        ];
        self::assertSame($expected, $this->notice($alice));
        // A second exercise, its predecessor not logged yet: the same announcement.
        $this->publish($this->exercise($alice, '2026-10-05 09:30:00'));
        self::assertSame($expected, $this->notice($alice));

        $this->acknowledge($alice);
        self::assertFalse($this->notice($alice)['pending']);
        $this->acknowledge($alice); // idempotent
        $this->publish($this->exercise($alice, '2026-10-05 09:45:00'));
        self::assertFalse($this->notice($alice)['pending'], 'shown once a day');

        // Tuesday: Monday's exercise logged, the streak goes on.
        $this->deliver($this->exercise($alice, '2026-10-05 09:00:00'));
        Clock::set(new MockClock('2026-10-06 06:00:00', 'UTC'));
        self::assertFalse($this->notice($alice)['pending']);
        $this->publish($this->exercise($alice, '2026-10-06 05:59:00'));
        $notice = $this->notice($alice);
        self::assertSame([true, 5, 4, [true, true, false, false, false, false, false]], [$notice['pending'], $notice['streak'], $notice['previousStreak'], $notice['week']]);
    }

    public function testABadgeABrokenStreakAndWhatAnnouncesNothing(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->deliver($this->exercise($alice, '2026-10-03 08:00:00'));
        $this->deliver($this->exercise($alice, '2026-10-04 08:00:00'));
        $this->publish($this->exercise($alice, '2026-10-05 08:00:00'));
        $notice = $this->notice($alice);
        self::assertSame([3, 2, 'streak_3', 7], [$notice['streak'], $notice['previousStreak'], $notice['badge'], $notice['nextMilestone']]);

        $bob = $this->createUser('bob@example.com');
        $this->deliver($this->exercise($bob, '2026-10-02 08:00:00'));
        // Rolled back with its exercise.
        $connection = $this->connection();
        $connection->beginTransaction();
        self::getContainer()->get(EventPublisher::class)->publish($this->exercise($bob, '2026-10-05 08:00:00'));
        $connection->rollBack();
        self::assertFalse($this->notice($bob)['pending']);
        // An exercise of a past day (a run closed after midnight, a backfill).
        $this->publish($this->exercise($bob, '2026-10-04 21:00:00'));
        self::assertFalse($this->notice($bob)['pending']);
        // Saturday and Sunday missed: the streak starts again.
        $this->publish($this->exercise($bob, '2026-10-05 08:00:00'));
        $notice = $this->notice($bob);
        self::assertSame([true, 1, 0, null, 3], [$notice['pending'], $notice['streak'], $notice['previousStreak'], $notice['badge'], $notice['nextMilestone']]);
        // Alice's announcement is hers only.
        $this->acknowledge($bob);
        self::assertTrue($this->notice($alice)['pending']);

        $this->client->request('GET', '/api/gamification/streak/notice');
        $read = $this->client->getResponse()->getStatusCode();
        $this->client->request('POST', '/api/gamification/streak/notice/acknowledgement');
        self::assertSame([401, 401], [$read, $this->client->getResponse()->getStatusCode()]);
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

    private function exercise(User $user, string $at): ExerciseCompleted
    {
        return new ExerciseCompleted(
            $user->getId()->toRfc4122(), ExerciseType::PuzzleRated, true, 20_000, 1, 'test', (string) ++$this->sequence, new \DateTimeImmutable($at, new \DateTimeZone('UTC')),
        );
    }

    /** Publishes an event as an exercise does: into the outbox, inside its transaction. */
    private function publish(ExerciseCompleted $event): void
    {
        $this->connection()->transactional(static fn () => self::getContainer()->get(EventPublisher::class)->publish($event));
    }

    /** Runs every handler of an event, as the worker does (the activity log). */
    private function deliver(object $event): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new Envelope($event, [new ReceivedStamp('activity')]));
    }

    /**
     * @return array<string, mixed>
     */
    private function notice(User $user): array
    {
        $this->client->request('GET', '/api/gamification/streak/notice', server: $this->auth($user));
        $response = $this->client->getResponse();
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return array_filter($body, static fn (string $key): bool => !str_starts_with($key, '@'), \ARRAY_FILTER_USE_KEY);
    }

    private function acknowledge(User $user): void
    {
        $this->client->request('POST', '/api/gamification/streak/notice/acknowledgement', server: $this->auth($user));
        self::assertSame(204, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
    }

    /**
     * @return array<string, string>
     */
    private function auth(User $user): array
    {
        return [
            'HTTP_AUTHORIZATION' => 'Bearer '.self::getContainer()->get(JWTTokenManagerInterface::class)->create($user),
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
