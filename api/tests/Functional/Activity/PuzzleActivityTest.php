<?php

declare(strict_types=1);

namespace App\Tests\Functional\Activity;

use App\Entity\Puzzle\Attempt;
use App\Enum\Activity\ExerciseType;
use App\Repository\Activity\LogEntryRepository;
use App\Tests\Functional\Puzzle\PuzzleWebTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

final class PuzzleActivityTest extends PuzzleWebTestCase
{
    use ActivityOutboxTrait;

    public function testEachSubmittedPuzzleEmitsTheCommonEventOnce(): void
    {
        $user = $this->createUser('alice@example.com');
        $attempt = $this->startAttempt($user);
        $this->api('POST', '/api/puzzles/attempts/'.$attempt['id'].'/submission', $user, ['moves' => self::playerMoves($attempt)]);
        // A second submission is refused (409): no second event.
        $this->api('POST', '/api/puzzles/attempts/'.$attempt['id'].'/submission', $user, ['moves' => []]);
        $replay = $this->attemptJson($this->api('POST', '/api/puzzles/attempts', $user, ['replayOf' => $attempt['puzzle']['id']]));
        $this->api('POST', '/api/puzzles/attempts/'.$replay['id'].'/submission', $user, ['moves' => []]);

        self::assertSame(2, $this->runOutbox());

        $repository = self::getContainer()->get(LogEntryRepository::class);
        $rated = $repository->findOneBySource('puzzle_attempt', $attempt['id']);
        self::assertNotNull($rated);
        self::assertSame(ExerciseType::PuzzleRated, $rated->getExerciseType());
        self::assertTrue($rated->isSuccess());
        self::assertSame(1, $rated->getItemCount());
        self::assertSame($attempt['puzzle']['id'], $rated->getMetadata()['puzzleId']);

        $unrated = $repository->findOneBySource('puzzle_attempt', $replay['id']);
        self::assertNotNull($unrated);
        self::assertSame(ExerciseType::PuzzleUnrated, $unrated->getExerciseType());
        self::assertFalse($unrated->isSuccess());
    }

    public function testTheBackfillLogsPastAttemptsOnceAndCanBeRerun(): void
    {
        $user = $this->createUser('alice@example.com');
        $puzzles = array_values($this->puzzles);
        // Attempts resolved "before Phase 4": no event was published for them.
        foreach ([0, 1, 2] as $i) {
            $attempt = new Attempt($user, $puzzles[$i], true, new \DateTimeImmutable('-1 hour'));
            $attempt->resolve(0 === $i, [], 0, 0, false, new \DateTimeImmutable(), null);
            $this->entityManager->persist($attempt);
        }
        $this->entityManager->persist(new Attempt($user, $puzzles[3], true, new \DateTimeImmutable()));
        $this->entityManager->flush();

        self::assertStringContainsString('puzzle_attempt: 3 logged, 0 already present', $this->backfill());
        self::assertStringContainsString('puzzle_attempt: 0 logged, 3 already present', $this->backfill());
        self::assertCount(3, self::getContainer()->get(LogEntryRepository::class)->findByUser($user));
    }

    private function backfill(): string
    {
        $tester = new CommandTester((new Application(self::$kernel ?? self::bootKernel()))->find('app:activity:backfill'));
        $tester->execute(['--batch-size' => '2']);
        $tester->assertCommandIsSuccessful();

        return $tester->getDisplay();
    }
}
