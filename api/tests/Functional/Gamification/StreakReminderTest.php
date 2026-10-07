<?php

declare(strict_types=1);

namespace App\Tests\Functional\Gamification;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Gamification\Streak\StreakReminderDue;
use App\Tests\Functional\Notification\PushTestTrait;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * The "streak in danger" reminder (docs/NOTIFICATIONS.md, § 5): due the day after an active day at
 * the chosen local hour (20 h by default), moved by an exercise played before, caught up for 30
 * minutes, sent once, by Web Push and by email when chosen. The test clock starts on Monday 28
 * September 2026, 12:00 in Paris (10:00 UTC); 20:00 in Paris is 18:00 UTC.
 */
final class StreakReminderTest extends WoodpeckerWebTestCase
{
    use PushTestTrait;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePushService();
    }

    public function testTheReminderIsSentOnceTheDayAfterAtTheChosenHour(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->subscribeBrowser($alice);
        self::assertSame(200, $this->api('PUT', '/api/gamification/streak/reminder', $alice, ['enabled' => true, 'hour' => 20, 'email' => true])->getStatusCode());
        $this->exercise($alice);

        // Tuesday 19:59 in Paris: not yet.
        $this->travel('+1 day 7 hours 59 minutes');
        self::assertSame(0, $this->sendReminders());
        $this->travel('+1 minute');
        self::assertSame(1, $this->sendReminders());
        self::assertSame(0, $this->sendReminders(), 'Once, whatever the cron overlaps.');

        $this->deliver();
        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertSame('Ta série de 1 jour s’arrête à minuit', $emails[0]->getSubject());
        self::assertCount(1, $this->pushRequests);
        self::assertSame(self::endpoint('alice'), $this->pushRequests[0]['url']);

        // Not played on Tuesday: the streak is broken, nothing more.
        $this->travel('+1 day');
        self::assertSame(0, $this->sendReminders());
    }

    public function testAnExercisePlayedBeforeTheHourMovesItToTheNextDay(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->exercise($alice);
        // Tuesday 14:00 in Paris.
        $this->travel('+1 day 2 hours');
        $this->exercise($alice);
        $this->travel('+6 hours');
        self::assertSame(0, $this->sendReminders(), 'Tuesday is safe.');
        $this->travel('+1 day');
        self::assertSame(1, $this->sendReminders(), 'Wednesday, the 2-day streak is in danger.');
    }

    public function testNothingIsSentWhenTheUserPlaysBeforeTheWorker(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->subscribeBrowser($alice);
        $this->exercise($alice);
        $this->travel('+1 day 8 hours');
        self::assertSame(1, $this->sendReminders());
        // Played between the queueing and the sending.
        $this->exercise($alice);
        $this->deliver();

        self::assertCount(0, $this->pushRequests);
    }

    public function testSettingsMoveOrTurnOffTheReminderAndAMissedOneIsCaughtUpForThirtyMinutes(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        self::assertSame(['enabled' => true, 'hour' => 20, 'email' => false], $this->settings($alice), 'the defaults');
        self::assertSame(422, $this->api('PUT', '/api/gamification/streak/reminder', $alice, ['enabled' => true, 'hour' => 17, 'email' => false])->getStatusCode());
        self::assertSame(422, $this->api('PUT', '/api/gamification/streak/reminder', $alice, ['enabled' => true, 'hour' => 24, 'email' => false])->getStatusCode());

        $this->exercise($alice);
        // 22 h: due on Tuesday at 22:00 in Paris (20:00 UTC).
        self::assertSame(200, $this->api('PUT', '/api/gamification/streak/reminder', $alice, ['enabled' => true, 'hour' => 22, 'email' => false])->getStatusCode());
        self::assertSame(['enabled' => true, 'hour' => 22, 'email' => false], $this->settings($alice));
        $this->travel('+1 day 8 hours');
        self::assertSame(0, $this->sendReminders());
        // The cron is back at 22:30: still sent.
        $this->travel('+2 hours 30 minutes');
        self::assertSame(1, $this->sendReminders());

        // Played on Tuesday at 22:30; on Wednesday the cron is back 31 minutes late: dropped.
        $this->exercise($alice);
        $this->travel('+1 day 1 minute');
        self::assertSame(0, $this->sendReminders());

        // Turned off.
        $this->exercise($alice);
        self::assertSame(200, $this->api('PUT', '/api/gamification/streak/reminder', $alice, ['enabled' => false, 'hour' => 22, 'email' => false])->getStatusCode());
        $this->travel('+1 day');
        self::assertSame(0, $this->sendReminders());

        $this->client->request('GET', '/api/gamification/streak/reminder');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());
    }

    /** A rated puzzle finished now, as an exercise publishes it, then logged by the outbox. */
    private function exercise(User $user): void
    {
        $event = new ExerciseCompleted(
            $user->getId()->toRfc4122(), ExerciseType::PuzzleRated, true, 20_000, 1, 'test', (string) ++$this->sequence, $this->clock->now(),
        );
        self::getContainer()->get(Connection::class)->transactional(static fn () => self::getContainer()->get(EventPublisher::class)->publish($event));
        $this->runOutbox();
    }

    /**
     * @return array<string, mixed>
     */
    private function settings(User $user): array
    {
        $response = $this->api('GET', '/api/gamification/streak/reminder', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return array_filter($this->json($response), static fn (string $key): bool => !str_starts_with($key, '@'), \ARRAY_FILTER_USE_KEY);
    }

    private function subscribeBrowser(User $user): void
    {
        $response = $this->api('POST', '/api/notifications/push/subscriptions', $user, ['endpoint' => self::endpoint('alice'), 'keys' => self::browserKeys()]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    }

    private function sendReminders(): int
    {
        $tester = new CommandTester((new Application(self::$kernel ?? throw new \LogicException('No kernel.')))->find('app:training:send-reminders'));
        $tester->execute([]);
        self::assertSame(0, $tester->getStatusCode());
        preg_match('/(\d+) reminder/', $tester->getDisplay(), $m);

        return (int) ($m[1] ?? -1);
    }

    /**
     * Runs the streak reminders queued on the async transport, as the worker would.
     */
    private function deliver(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof StreakReminderDue) {
                $bus->dispatch($envelope->with(new ReceivedStamp('async')));
            }
        }
    }

    /**
     * @return list<Email> the emails sent (rendered)
     */
    private function emails(): array
    {
        return array_values(array_filter(self::getMailerMessages(), static fn (object $m): bool => $m instanceof Email));
    }
}
