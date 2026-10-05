<?php

declare(strict_types=1);

namespace App\Tests\Functional\Ops;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\Activity\LogEntry;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Ops\Queue\DrainOnTerminateListener;
use App\Ops\Queue\QueueDrainer;
use App\Ops\Tick\TickRunner;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Hosting without worker (docs/DEPLOY_OVH.md): the minute's tick (reminders, then the queues) and
 * the drain of the activity queue after write requests.
 *
 * @phpstan-import-type Params from DriverManager
 */
final class TickTest extends WoodpeckerWebTestCase
{
    private const TOKEN = 'test-tick-token-0123456789abcdef-0123';

    private int $sequence = 0;

    public function testTheTickNeedsItsSecret(): void
    {
        self::assertSame(404, $this->tick(null)->getStatusCode());
        self::assertSame(404, $this->tick('test-tick-token-0123456789abcdef-0124')->getStatusCode());
        $this->client->request('GET', '/api/ops/tick', server: ['HTTP_X_TICK_TOKEN' => self::TOKEN]);
        self::assertSame(405, $this->client->getResponse()->getStatusCode());
    }

    public function testTheTickQueuesTheDueRemindersAndSendsThem(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->plan($alice);
        // 18:00 in Paris: the reminder of 18:30, 30 minutes ahead.
        $this->travel('+6 hours');

        $response = $this->tick(self::TOKEN);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response);
        self::assertFalse($result['busy']);
        self::assertSame(1, $result['reminders']);
        // The reminder queued by this very tick is handled by it. (The email it queues in turn is
        // not visible here: "async" is an in-memory transport in tests, emptied with the services
        // reset after each message, as `messenger:consume` does. In production it is a Doctrine
        // table, drained in the same tick: testTheTickHandlesTheDomainEvents shows a real one.)
        self::assertSame(1, $result['handled']);
        self::assertSame(0, $result['failed']);
        self::assertTrue($response->headers->hasCacheControlDirective('no-store'));

        // Claimed once: the next tick of the same minute queues nothing.
        self::assertSame(0, $this->json($this->tick(self::TOKEN))['reminders']);
    }

    public function testTheTickHandlesTheDomainEvents(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->exercise($alice);
        self::assertSame(1, $this->outbox()->getMessageCount());

        $result = $this->json($this->tick(self::TOKEN));

        self::assertSame(0, $this->outbox()->getMessageCount());
        self::assertGreaterThanOrEqual(1, $result['handled']);
        self::assertSame(1, $this->entityManager->getRepository(LogEntry::class)->count([]));
    }

    public function testOneTickAtATime(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->exercise($alice);
        // Another tick holds the lock, on its own MySQL session.
        /** @var Params $params */
        $params = self::getContainer()->get(Connection::class)->getParams();
        $other = DriverManager::getConnection($params);
        self::assertEquals(1, $other->fetchOne('SELECT GET_LOCK(?, 0)', [TickRunner::LOCK]));

        try {
            $result = $this->json($this->tick(self::TOKEN));
        } finally {
            $other->fetchOne('SELECT RELEASE_LOCK(?)', [TickRunner::LOCK]);
            $other->close();
        }

        self::assertTrue($result['busy']);
        self::assertSame(1, $this->outbox()->getMessageCount());
    }

    public function testAWriteRequestHandlesActivityOnceAnswered(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $drainer = self::getContainer()->get(QueueDrainer::class);

        // Off (the default), or after a read: nothing is handled.
        $this->exercise($alice);
        $this->terminate(new DrainOnTerminateListener($drainer, false), 'POST');
        $this->terminate(new DrainOnTerminateListener($drainer, true), 'GET');
        self::assertSame(1, $this->outbox()->getMessageCount());

        $this->terminate(new DrainOnTerminateListener($drainer, true), 'POST');

        self::assertSame(0, $this->outbox()->getMessageCount());
        self::assertSame(1, $this->entityManager->getRepository(LogEntry::class)->count([]));
    }

    private function tick(?string $token): Response
    {
        $server = null === $token ? [] : ['HTTP_X_TICK_TOKEN' => $token];
        $this->client->request('POST', '/api/ops/tick', server: $server);

        return $this->client->getResponse();
    }

    private function terminate(DrainOnTerminateListener $listener, string $method): void
    {
        $kernel = self::$kernel;
        self::assertInstanceOf(KernelInterface::class, $kernel);
        $listener(new TerminateEvent($kernel, Request::create('/api/puzzles/attempts', $method), new Response()));
    }

    private function plan(User $user): void
    {
        $response = $this->api('POST', '/api/training/plans', $user, [
            'title' => 'Soirs de semaine',
            'steps' => [['module' => 'free', 'minutes' => 10, 'settings' => ['format' => 'book']]],
            'repetition' => 'daily',
            'weekdays' => [1, 2, 3, 4, 5],
            'time' => '18:30',
            'reminderEnabled' => true,
            'reminderMinutes' => 30,
            'reminderChannels' => ['email'],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    /** A domain event in the outbox, as an exercise would leave it. */
    private function exercise(User $user): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new ExerciseCompleted(
            $user->getId()->toRfc4122(), ExerciseType::PuzzleRated, true, 20_000, 1, 'test', (string) ++$this->sequence, new \DateTimeImmutable('2026-09-28 10:00:00', new \DateTimeZone('UTC')),
        ));
    }
}
