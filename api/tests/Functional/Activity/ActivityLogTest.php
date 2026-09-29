<?php

declare(strict_types=1);

namespace App\Tests\Functional\Activity;

use App\Activity\Event\ExerciseCompleted;
use App\Activity\EventPublisher;
use App\Activity\Log\ActivityLogger;
use App\Entity\User;
use App\Enum\Activity\ExerciseType;
use App\Repository\Activity\LogEntryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class ActivityLogTest extends KernelTestCase
{
    use ActivityOutboxTrait;

    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = self::getContainer()->get(EntityManagerInterface::class);
    }

    public function testAnEventIsEmittedOnlyOnceItsTransactionCommits(): void
    {
        $user = $this->createUser('Europe/Paris');
        $publisher = self::getContainer()->get(EventPublisher::class);

        $this->entityManager->wrapInTransaction(fn () => $publisher->publish($this->event($user, 'a')));

        self::assertSame(1, $this->outbox()->getMessageCount());
        self::assertSame(1, $this->runOutbox());
        self::assertNotNull(self::getContainer()->get(LogEntryRepository::class)->findOneBySource('test', 'a'));
    }

    public function testNothingIsEmittedWhenTheTransactionRollsBack(): void
    {
        $user = $this->createUser('Europe/Paris');
        $publisher = self::getContainer()->get(EventPublisher::class);

        try {
            $this->entityManager->wrapInTransaction(function () use ($publisher, $user): void {
                $publisher->publish($this->event($user, 'b'));
                throw new \RuntimeException('the exercise failed to save');
            });
        } catch (\RuntimeException) {
        }

        self::assertSame(0, $this->outbox()->getMessageCount());
        self::assertSame(0, $this->runOutbox());
        self::assertNull(self::getContainer()->get(LogEntryRepository::class)->findOneBySource('test', 'b'));
    }

    public function testTheLocalDateUsesTheUserTimezoneAtWriteTimeAndNeverChanges(): void
    {
        $user = $this->createUser('Europe/Paris');
        $logger = self::getContainer()->get(ActivityLogger::class);

        // 2026-07-14 22:30 UTC = 2026-07-15 00:30 in Paris.
        $logger->record($this->event($user, 'c', '2026-07-14 22:30:00'));
        $user->setTimezone('America/New_York');
        $this->entityManager->flush();
        $logger->record($this->event($user, 'd', '2026-07-14 22:30:00'));

        $repository = self::getContainer()->get(LogEntryRepository::class);
        $paris = $repository->findOneBySource('test', 'c');
        $newYork = $repository->findOneBySource('test', 'd');
        self::assertNotNull($paris);
        self::assertNotNull($newYork);
        self::assertSame('2026-07-15', $paris->getLocalDate()->format('Y-m-d'));
        self::assertSame('Europe/Paris', $paris->getTimezone());
        self::assertSame('2026-07-14', $newYork->getLocalDate()->format('Y-m-d'));
        self::assertSame('2026-07-14 22:30:00', $paris->getOccurredAt()->format('Y-m-d H:i:s'));
    }

    public function testTheSameExerciseIsLoggedOnce(): void
    {
        $user = $this->createUser(null);
        $logger = self::getContainer()->get(ActivityLogger::class);

        self::assertTrue($logger->record($this->event($user, 'e')));
        self::assertFalse($logger->record($this->event($user, 'e')));

        self::assertCount(1, self::getContainer()->get(LogEntryRepository::class)->findByUser($user));
        self::assertSame('UTC', self::getContainer()->get(LogEntryRepository::class)->findOneBySource('test', 'e')?->getTimezone());
    }

    private function createUser(?string $timezone): User
    {
        $user = new User();
        $user->setEmail(bin2hex(random_bytes(4)).'@example.com');
        $user->setTimezone($timezone);
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function event(User $user, string $sourceId, string $at = '2026-09-28 10:00:00'): ExerciseCompleted
    {
        return new ExerciseCompleted(
            userId: $user->getId()->toRfc4122(),
            type: ExerciseType::PuzzleRated,
            success: true,
            durationMs: 12_000,
            itemCount: 1,
            sourceType: 'test',
            sourceId: $sourceId,
            occurredAt: new \DateTimeImmutable($at, new \DateTimeZone('UTC')),
            metadata: ['k' => 'v'],
        );
    }
}
