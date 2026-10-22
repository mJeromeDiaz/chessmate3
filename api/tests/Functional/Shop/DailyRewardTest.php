<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Tests\Double\SwitchableStreakReward;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Uid\Uuid;

/**
 * The daily chest (docs/SHOP.md, § 4 ter, rules validated on 2026-10-21): 6 to 12 crystals with the
 * streak's announcement (the first exercise of the local day), a streak badge's bonus the day it is
 * won (10 per day), credited at once, rolled back with its exercise, shown once. Today is Monday
 * 2026-10-05 in Paris.
 *
 * @phpstan-type DailyJson array{id: string, localDate: string, streak: int, badgeDays: int|null, dailyCrowns: int, badgeCrowns: int, crowns: int, opened: bool, balance: int}
 */
final class DailyRewardTest extends WebTestCase
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
        SwitchableStreakReward::$enabled = true;
    }

    protected function tearDown(): void
    {
        SwitchableStreakReward::$enabled = false;
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheFirstExerciseOfTheDayEarnsAChestOpenedOnce(): void
    {
        $alice = $this->createUser('alice@example.com');
        $this->deliver($this->exercise($alice, '2026-10-04 08:00:00'));
        self::assertSame([], $this->unopened($alice));

        $this->publish($this->exercise($alice, '2026-10-05 09:00:00'));
        // A second exercise of the day earns nothing more.
        $this->publish($this->exercise($alice, '2026-10-05 09:30:00'));
        $chests = $this->unopened($alice);
        self::assertCount(1, $chests);
        $chest = $chests[0];
        self::assertSame(['2026-10-05', 2, null, 0, false], [$chest['localDate'], $chest['streak'], $chest['badgeDays'], $chest['badgeCrowns'], $chest['opened']]);
        self::assertGreaterThanOrEqual(6, $chest['dailyCrowns']);
        self::assertLessThanOrEqual(12, $chest['dailyCrowns']);
        // Credited when earned: the chest only reveals it.
        self::assertSame([$chest['dailyCrowns'], $chest['dailyCrowns']], [$chest['crowns'], $chest['balance']]);
        self::assertSame([['reward', $chest['crowns'], 'Coffre du jour']], $this->ledger($alice));

        $response = $this->api('POST', '/api/shop/daily-rewards/'.$chest['id'].'/open', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $opened = $this->json($response);
        self::assertSame([$chest['id'], true, $chest['crowns']], [$opened['id'] ?? null, $opened['opened'] ?? null, $opened['balance'] ?? null]);
        self::assertSame(200, $this->api('POST', '/api/shop/daily-rewards/'.$chest['id'].'/open', $alice)->getStatusCode(), 'idempotent');
        self::assertSame([], $this->unopened($alice), 'shown once');
        self::assertCount(1, $this->ledger($alice), 'opening credits nothing more');

        // Tuesday: a new chest.
        $this->deliver($this->exercise($alice, '2026-10-05 09:00:00'));
        Clock::set(new MockClock('2026-10-06 07:00:00', 'UTC'));
        $this->publish($this->exercise($alice, '2026-10-06 06:59:00'));
        $tuesday = $this->unopened($alice);
        self::assertSame([['2026-10-06', 3]], array_map(static fn (array $chest): array => [$chest['localDate'], $chest['streak']], $tuesday));

        // Another player's chest, an unknown one, no credentials.
        $bob = $this->createUser('bob@example.com');
        self::assertSame(404, $this->api('POST', '/api/shop/daily-rewards/'.$tuesday[0]['id'].'/open', $bob)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/shop/daily-rewards/'.Uuid::v7()->toRfc4122().'/open', $alice)->getStatusCode());
        self::assertSame([], $this->unopened($bob));
        $this->client->request('GET', '/api/shop/daily-rewards/unopened');
        $read = $this->client->getResponse()->getStatusCode();
        $this->client->request('POST', '/api/shop/daily-rewards/'.$tuesday[0]['id'].'/open');
        self::assertSame([401, 401], [$read, $this->client->getResponse()->getStatusCode()]);
    }

    public function testAStreakBadgeAddsItsBonusToTheDaysChest(): void
    {
        $alice = $this->createUser('alice@example.com');
        foreach (['2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02', '2026-10-03', '2026-10-04'] as $day) {
            $this->deliver($this->exercise($alice, $day.' 08:00:00'));
        }
        $this->publish($this->exercise($alice, '2026-10-05 08:00:00'));
        $chest = $this->unopened($alice)[0] ?? null;
        self::assertNotNull($chest);
        // 7 days: 70 more, in the same chest.
        self::assertSame([7, 7, 70, $chest['dailyCrowns'] + 70, $chest['dailyCrowns'] + 70], [$chest['streak'], $chest['badgeDays'], $chest['badgeCrowns'], $chest['crowns'], $chest['balance']]);
        self::assertSame([['reward', $chest['crowns'], 'Coffre de série · 7 jours']], $this->ledger($alice));
    }

    public function testRolledBackWithItsExerciseAndNothingForAPastDay(): void
    {
        $alice = $this->createUser('alice@example.com');
        $connection = $this->connection();
        $connection->beginTransaction();
        self::getContainer()->get(EventPublisher::class)->publish($this->exercise($alice, '2026-10-05 08:00:00'));
        $connection->rollBack();
        // A run closed after midnight, a backfill.
        $this->publish($this->exercise($alice, '2026-10-04 21:00:00'));
        self::assertSame([[], []], [$this->unopened($alice), $this->ledger($alice)]);

        $this->publish($this->exercise($alice, '2026-10-05 08:00:00'));
        self::assertCount(1, $this->unopened($alice));
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
     * @return list<DailyJson>
     */
    private function unopened(User $user): array
    {
        $response = $this->api('GET', '/api/shop/daily-rewards/unopened', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{member: list<DailyJson>} $body */
        $body = $this->json($response);

        return $body['member'];
    }

    /**
     * @return list<array{0: mixed, 1: int, 2: mixed}> kind, delta, reason
     */
    private function ledger(User $user): array
    {
        return array_map(static fn (array $row): array => [$row['kind'], is_numeric($row['delta']) ? (int) $row['delta'] : 0, $row['reason']], $this->connection()->fetchAllAssociative(
            'SELECT kind, delta, reason FROM shop_ledger_entry WHERE user_id = ? ORDER BY created_at, id',
            [$user->getId()->toBinary()],
        ));
    }

    private function api(string $method, string $uri, User $user): Response
    {
        $this->client->request($method, $uri, server: [
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
        /** @var array<string, mixed> $body */
        $body = json_decode((string) $response->getContent(), true, flags: \JSON_THROW_ON_ERROR);

        return $body;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
