<?php

declare(strict_types=1);

namespace App\Tests\Functional\Training;

use App\Entity\User;
use App\Tests\Functional\Notification\PushTestTrait;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use App\Training\Plan\Reminder\SessionReminderDue;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Mime\Email;

/**
 * Reminders of saved sessions (docs/TRAINING.md, rules validated on 2026-10-04): due from the
 * chosen delay before an occurrence, caught up to 15 min late but never once the session has
 * begun, skipped when the plan was already launched that day, sent once per occurrence, by email
 * and Web Push. The test clock starts on Monday 28 September 2026, 12:00 in Paris (10:00 UTC).
 */
final class ReminderTest extends WoodpeckerWebTestCase
{
    use PushTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fakePushService();
    }

    public function testAReminderIsSentOnceAtTheChosenDelay(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->subscribeBrowser($alice);
        $this->plan($alice, ['time' => '18:30', 'reminderMinutes' => 30, 'reminderChannels' => ['email', 'push']]);

        // 17:59 in Paris: not yet.
        $this->travel('+5 hours 59 minutes');
        self::assertSame(0, $this->sendReminders());
        $this->travel('+1 minute');
        self::assertSame(1, $this->sendReminders());
        self::assertSame(0, $this->sendReminders(), 'Once per occurrence, whatever the cron overlaps.');
        $this->travel('+1 minute');
        self::assertSame(0, $this->sendReminders());

        $this->deliver();
        $emails = $this->emails();
        self::assertCount(1, $emails);
        self::assertSame('Rappel : Soirs de semaine à 18:30', $emails[0]->getSubject());
        self::assertStringContainsString('Libre · 10 min', (string) $emails[0]->getTextBody());
        self::assertCount(1, $this->pushRequests);
        self::assertSame(self::endpoint('alice'), $this->pushRequests[0]['url']);

        // The next day, again.
        $this->travel('+1 day');
        self::assertSame(1, $this->sendReminders());
    }

    public function testAMissedReminderIsCaughtUpForFifteenMinutesOnly(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->plan($alice, ['time' => '18:30', 'reminderMinutes' => 60]);

        // Due at 17:30; the cron comes back at 17:44.
        $this->travel('+5 hours 44 minutes');
        self::assertSame(1, $this->sendReminders());

        // The next day, it only comes back at 17:46: too late.
        $this->travel('+1 day 2 minutes');
        self::assertSame(0, $this->sendReminders());
    }

    public function testNoReminderOnceTheSessionHasBegun(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        // 10 min before 18:30; the cron is back at 18:31 (12 min late, but the session has begun).
        $this->plan($alice, ['time' => '18:30', 'reminderMinutes' => 10]);

        $this->travel('+6 hours 31 minutes');
        self::assertSame(0, $this->sendReminders());
    }

    public function testNoReminderWhenTheSessionWasAlreadyLaunchedThatDay(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $plan = $this->plan($alice, ['time' => '18:30', 'reminderMinutes' => 30]);

        // Played at 17:00 instead of 18:30.
        $this->travel('+5 hours');
        self::assertSame(201, $this->api('POST', '/api/training/plans/'.$plan.'/launch', $alice)->getStatusCode());
        $this->travel('+1 hour');
        self::assertSame(0, $this->sendReminders());

        // Tomorrow it is reminded again.
        $this->travel('+1 day');
        self::assertSame(1, $this->sendReminders());
    }

    public function testOnlyTheChosenChannelsAndAVerifiedAddress(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->subscribeBrowser($alice);
        $this->plan($alice, ['time' => '18:30', 'reminderMinutes' => 30, 'reminderChannels' => ['push']]);
        // An address never verified.
        $bob = (new User())->setEmail('bob@example.com');
        $bob->setTimezone('Europe/Paris');
        $this->entityManager->persist($bob);
        $this->entityManager->flush();
        $this->plan($bob, ['time' => '18:30', 'reminderMinutes' => 30, 'reminderChannels' => ['email']]);

        $this->travel('+6 hours');
        self::assertSame(2, $this->sendReminders());
        $this->deliver();

        self::assertSame([], array_map(static fn (Email $e): string => (string) $e->getSubject(), $this->emails()), 'Push only for Alice; Bob\'s address is not verified.');
        self::assertCount(1, $this->pushRequests);
    }

    public function testNoReminderForAPlanChangedOrDeletedMeanwhile(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $plan = $this->plan($alice, ['time' => '18:30', 'reminderMinutes' => 30]);
        $this->travel('+6 hours');
        self::assertSame(1, $this->sendReminders());

        // Turned off before the worker sends it.
        $this->api('PUT', '/api/training/plans/'.$plan, $alice, $this->body(['time' => '18:30', 'reminderEnabled' => false]));
        $this->deliver();

        self::assertSame([], $this->emails());
    }

    /**
     * A daily plan, Monday to Friday, with a reminder (email by default).
     *
     * @param array<string, mixed> $overrides
     */
    private function plan(User $user, array $overrides): string
    {
        $response = $this->api('POST', '/api/training/plans', $user, $this->body($overrides));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->json($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function body(array $overrides): array
    {
        return $overrides + [
            'title' => 'Soirs de semaine',
            'steps' => [['module' => 'free', 'minutes' => 10, 'settings' => ['format' => 'book']]],
            'repetition' => 'daily',
            'weekdays' => [1, 2, 3, 4, 5],
            'reminderEnabled' => true,
            'reminderChannels' => ['email'],
        ];
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
     * Runs the reminders queued on the async transport, as the worker would.
     */
    private function deliver(): void
    {
        $transport = $this->async();
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ($transport->getSent() as $envelope) {
            if ($envelope->getMessage() instanceof SessionReminderDue) {
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

    private function async(): InMemoryTransport
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);

        return $transport;
    }
}
