<?php

declare(strict_types=1);

namespace App\Tests\Functional\Woodpecker;

use App\Activity\Event\ExerciseCompleted;
use App\Enum\Activity\ExerciseType;
use App\Repository\Activity\LogEntryRepository;
use App\Woodpecker\Event\CycleCompleted;
use App\Woodpecker\Event\CycleLost;
use App\Woodpecker\Event\SetCompleted;
use Doctrine\DBAL\Connection;

final class WoodpeckerApiTest extends WoodpeckerWebTestCase
{
    public function testASetIsAFrozenListMatchingTheCriteria(): void
    {
        $user = $this->createUserIn('alice@example.com');

        $set = $this->createSet($user, ['puzzleCount' => 8, 'ratingMin' => 900, 'ratingMax' => 2600, 'themes' => ['endgame', 'mate']]);

        self::assertSame('active', $set['status']);
        self::assertSame(8, $set['puzzleCount']);
        /** @var list<array{position: int|string, rating: int|string, themes: string, selectable: int|string}> $rows */
        $rows = self::getContainer()->get(Connection::class)->fetchAllAssociative(
            'SELECT sp.position, p.rating, p.themes, p.selectable FROM woodpecker_set_puzzle sp JOIN puzzle p ON p.id = sp.puzzle_id WHERE sp.set_id = UNHEX(REPLACE(:id, \'-\', \'\')) ORDER BY sp.position',
            ['id' => $set['id']],
        );
        self::assertCount(8, $rows);
        self::assertSame(range(0, 7), array_map(static fn (array $row): int => (int) $row['position'], $rows));
        foreach ($rows as $row) {
            self::assertGreaterThanOrEqual(900, (int) $row['rating']);
            self::assertLessThanOrEqual(2600, (int) $row['rating']);
            self::assertSame(1, (int) $row['selectable']);
            /** @var list<string> $themes */
            $themes = json_decode($row['themes'], true);
            self::assertNotEmpty(array_intersect(['endgame', 'mate'], $themes));
        }
        // The first run is open, its deadline 4 local days later (end of 2026-10-01 in Paris).
        self::assertNotNull($set['current']);
        self::assertSame([1, 1, 'active', 4], [$set['current']['number'], $set['current']['run'], $set['current']['status'], $set['current']['durationDays']]);
        self::assertSame('2026-10-01T22:00:00+00:00', $set['current']['deadlineAt']);
    }

    public function testCreationRefusesTooFewPuzzlesUnknownThemesAndBadRanges(): void
    {
        $user = $this->createUserIn('alice@example.com');

        self::assertSame(422, $this->api('POST', '/api/woodpecker/sets', $user, ['name' => 'x', 'puzzleCount' => 4])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/woodpecker/sets', $user, ['name' => 'x', 'puzzleCount' => 1501])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/woodpecker/sets', $user, ['name' => 'x', 'puzzleCount' => 30, 'themes' => ['mateIn1'], 'ratingMin' => 400, 'ratingMax' => 3200])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/woodpecker/sets', $user, ['name' => 'x', 'puzzleCount' => 5, 'themes' => ['notATheme']])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/woodpecker/sets', $user, ['name' => 'x', 'puzzleCount' => 5, 'ratingMin' => 1500])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/woodpecker/sets', $user, ['name' => 'x', 'puzzleCount' => 5, 'ratingMin' => 1500, 'ratingMax' => 1550])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/woodpecker/sets', $user, ['name' => '', 'puzzleCount' => 5])->getStatusCode());
    }

    public function testOnlyOneSetAtATime(): void
    {
        $user = $this->createUserIn('alice@example.com');
        $set = $this->createSet($user);

        $second = ['name' => 'Second', 'puzzleCount' => 5, 'ratingMin' => 400, 'ratingMax' => 3200];
        $whileActive = $this->api('POST', '/api/woodpecker/sets', $user, $second)->getStatusCode();
        $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/pause', $user);
        $whilePaused = $this->api('POST', '/api/woodpecker/sets', $user, $second)->getStatusCode();
        self::assertSame([409, 409], [$whileActive, $whilePaused]);

        self::assertSame(200, $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/abandon', $user)->getStatusCode());
        $this->createSet($user, ['name' => 'Second']);
    }

    public function testACycleRunsToTheNextOneThenTheSetCompletes(): void
    {
        $user = $this->createUserIn('alice@example.com');
        $set = $this->createSet($user, ['cycleCount' => 2, 'firstCycleDays' => 4, 'reductionFactor' => 0.5]);

        $first = $this->next($user, $set['id']);
        self::assertSame($first['id'], $this->next($user, $set['id'])['id'], 'the pending puzzle is handed back');
        $played = $this->play($user, $set['id']);
        self::assertSame('solved', $played['status']);
        self::assertSame(1, $played['set']['current']['played'] ?? null);

        $order1 = [$played['puzzle']['id']];
        for ($i = 1; $i < 5; ++$i) {
            $order1[] = $this->play($user, $set['id'])['puzzle']['id'];
        }

        $after = $this->getSet($user, $set['id']);
        self::assertSame(['completed', 'active'], array_column($after['cycles'], 'status'));
        self::assertNotNull($after['current']);
        self::assertSame([2, 1, 2], [$after['current']['number'], $after['current']['run'], $after['current']['durationDays']]);
        self::assertSame(1.0, (float) $after['cycles'][0]['accuracy']);
        self::assertTrue($after['cycles'][0]['onTime']);

        // Same order in every cycle (not shuffled).
        $order2 = [];
        for ($i = 0; $i < 5; ++$i) {
            $order2[] = $this->play($user, $set['id'])['puzzle']['id'];
        }
        self::assertSame($order1, $order2);

        $done = $this->getSet($user, $set['id']);
        self::assertSame('completed', $done['status']);
        self::assertNull($done['current']);
        self::assertSame(409, $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/attempts', $user)->getStatusCode());

        $events = array_map(static fn ($envelope): object => $envelope->getMessage(), [...$this->outbox()->get(), ...$this->drainOutbox()]);
        self::assertCount(10, array_filter($events, static fn (object $e): bool => $e instanceof ExerciseCompleted));
        self::assertCount(2, array_filter($events, static fn (object $e): bool => $e instanceof CycleCompleted));
        self::assertCount(1, array_filter($events, static fn (object $e): bool => $e instanceof SetCompleted));
    }

    public function testActiveTimeIsCappedPerAttemptAndSummedAsNumbers(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice);

        // 40 s then 7 min (capped at 5 min): as strings, LEAST('40000', '300000') would give '300000'.
        foreach (['+40 seconds', '+7 minutes'] as $spent) {
            $attempt = $this->next($alice, $set['id']);
            $this->travel($spent);
            $response = $this->api('POST', '/api/woodpecker/attempts/'.$attempt['id'].'/submission', $alice, ['moves' => self::solution($attempt)]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        }

        $current = $this->getSet($alice, $set['id'])['current'];
        self::assertNotNull($current);
        self::assertSame([2, 340_000, 170_000], [$current['played'], $current['activeMs'], $current['averageMs']]);
    }

    public function testShuffledCyclesUseADifferentOrder(): void
    {
        $user = $this->createUserIn('alice@example.com');
        $set = $this->createSet($user, ['puzzleCount' => 20, 'shuffle' => true]);

        $order1 = [];
        for ($i = 0; $i < 20; ++$i) {
            $order1[] = $this->play($user, $set['id'])['puzzle']['id'];
        }
        $order2 = [];
        for ($i = 0; $i < 20; ++$i) {
            $order2[] = $this->play($user, $set['id'])['puzzle']['id'];
        }

        self::assertEqualsCanonicalizing($order1, $order2);
        self::assertNotSame($order1, $order2);
    }

    public function testALateCycleIsLostAndTheSameCycleRestarts(): void
    {
        $user = $this->createUserIn('alice@example.com');
        $set = $this->createSet($user, ['firstCycleDays' => 2]);
        $this->play($user, $set['id']);
        $pending = $this->next($user, $set['id']);

        // Deadline: end of 2026-09-29 in Paris. Come back on 2026-10-05.
        $this->travel('+7 days');
        $after = $this->getSet($user, $set['id']);

        self::assertSame(['lost', 'active'], array_column($after['cycles'], 'status'));
        self::assertFalse($after['cycles'][0]['onTime']);
        self::assertSame(1, $after['cycles'][0]['played']);
        self::assertNotNull($after['current']);
        self::assertSame([1, 2, 2, 0], [$after['current']['number'], $after['current']['run'], $after['current']['durationDays'], $after['current']['played']]);
        self::assertSame('2026-10-06T22:00:00+00:00', $after['current']['deadlineAt']);

        // The pending attempt of the lost run can no longer be submitted.
        self::assertSame(409, $this->api('POST', '/api/woodpecker/attempts/'.$pending['id'].'/submission', $user, ['moves' => self::solution($pending)])->getStatusCode());
        self::assertCount(1, array_filter(array_map(static fn ($e): object => $e->getMessage(), $this->drainOutbox()), static fn (object $e): bool => $e instanceof CycleLost));
    }

    public function testRestDelaysTheNextCycleToALocalMidnight(): void
    {
        $user = $this->createUserIn('alice@example.com');
        $set = $this->createSet($user, ['restDays' => 1]);
        $this->finishRun($user, $set['id']);

        $resting = $this->getSet($user, $set['id']);
        self::assertNotNull($resting['current']);
        self::assertSame('resting', $resting['current']['status']);
        // Completed 2026-09-28 in Paris, one rest day (the 29th) → available 2026-09-30 00:00 Paris.
        self::assertSame('2026-09-29T22:00:00+00:00', $resting['current']['availableAt']);
        self::assertSame(409, $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/attempts', $user)->getStatusCode());

        $this->travel('+2 days');
        self::assertSame('active', $this->next($user, $set['id'])['set']['current']['status'] ?? null);
    }

    public function testAPauseFreezesTheSetAndShiftsTheDeadline(): void
    {
        $user = $this->createUserIn('alice@example.com');
        $set = $this->createSet($user, ['firstCycleDays' => 2]);
        self::assertNotNull($set['current']);
        self::assertSame('2026-09-29T22:00:00+00:00', $set['current']['deadlineAt']);

        self::assertSame('paused', $this->setJson($this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/pause', $user))['status']);
        self::assertSame(409, $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/attempts', $user)->getStatusCode());
        self::assertSame(409, $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/pause', $user)->getStatusCode());

        // Ten days of pause, far past the original deadline: nothing is lost.
        $this->travel('+10 days');
        $resumed = $this->setJson($this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/resume', $user));

        self::assertSame('active', $resumed['status']);
        self::assertSame(['active'], array_column($resumed['cycles'], 'status'));
        self::assertNotNull($resumed['current']);
        self::assertSame('2026-10-09T22:00:00+00:00', $resumed['current']['deadlineAt']);
    }

    public function testDeadlinesAreLocalDayEndsAcrossTheDstChange(): void
    {
        $this->clock->modify('2026-10-20 10:00:00');
        $paris = $this->createUserIn('alice@example.com', 'Europe/Paris');
        $tokyo = $this->createUserIn('bob@example.com', 'Asia/Tokyo');

        // 7 local days from 2026-10-20: end of the 26th. Paris is back to UTC+1 by then.
        self::assertSame('2026-10-26T23:00:00+00:00', $this->createSet($paris, ['firstCycleDays' => 7])['current']['deadlineAt'] ?? null);
        self::assertSame('2026-10-26T15:00:00+00:00', $this->createSet($tokyo, ['firstCycleDays' => 7])['current']['deadlineAt'] ?? null);
    }

    public function testAnotherUsersSetAndAttemptsAreInvisible(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $mallory = $this->createUserIn('mallory@example.com');
        $set = $this->createSet($alice);
        $attempt = $this->next($alice, $set['id']);

        self::assertSame(404, $this->api('GET', '/api/woodpecker/sets/'.$set['id'], $mallory)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/attempts', $mallory)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/abandon', $mallory)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/woodpecker/sets/'.$set['id'].'/stubborn', $mallory)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/woodpecker/attempts/'.$attempt['id'].'/submission', $mallory, ['moves' => self::solution($attempt)])->getStatusCode());
        self::assertSame([], $this->json($this->api('GET', '/api/woodpecker/sets', $mallory))['member']);
        self::assertSame('active', $this->getSet($alice, $set['id'])['status']);
    }

    public function testASubmissionIsJudgedByTheServerAndAcceptedOnce(): void
    {
        $user = $this->createUserIn('alice@example.com');
        $set = $this->createSet($user);
        $attempt = $this->next($user, $set['id']);
        $uri = '/api/woodpecker/attempts/'.$attempt['id'].'/submission';

        self::assertSame(400, $this->api('POST', $uri, $user, ['moves' => ['a1a1']])->getStatusCode());
        $forged = $this->json($this->api('POST', $uri, $user, ['moves' => [], 'status' => 'solved', 'success' => true]));
        self::assertSame('failed', $forged['status']);
        self::assertSame(409, $this->api('POST', $uri, $user, ['moves' => self::solution($attempt)])->getStatusCode());
        self::assertSame(1, $this->getSet($user, $set['id'])['current']['played'] ?? null);
    }

    public function testActiveSetPuzzlesAreKeptOutOfTheRatedSelectionUntilTheSetEnds(): void
    {
        $user = $this->createUserIn('alice@example.com');
        // 45 of the 48 selectable sample puzzles.
        $set = $this->createSet($user, ['puzzleCount' => 45]);
        $inSet = array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT puzzle_id FROM woodpecker_set_puzzle WHERE set_id = UNHEX(REPLACE(:id, \'-\', \'\'))',
            ['id' => $set['id']],
        ));

        for ($i = 0; $i < 3; ++$i) {
            $rated = $this->startAttempt($user);
            self::assertNotContains((int) $this->puzzles[$rated['puzzle']['id']]->getId(), $inSet);
            $this->api('POST', '/api/puzzles/attempts/'.$rated['id'].'/submission', $user, ['moves' => []]);
        }
        // Every selectable puzzle outside the set is used: nothing left while the set is active.
        self::assertSame(404, $this->api('POST', '/api/puzzles/attempts', $user, [])->getStatusCode());

        $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/abandon', $user);
        $rated = $this->startAttempt($user);
        self::assertContains((int) $this->puzzles[$rated['puzzle']['id']]->getId(), $inSet);
    }

    public function testStubbornPuzzlesAreListedAndReplayableUnrated(): void
    {
        $user = $this->createUserIn('alice@example.com');
        $set = $this->createSet($user);
        // Fail the first puzzle of cycles 1 and 2 (same order), solve the others.
        $failed = $this->play($user, $set['id'], []);
        $this->finishRun($user, $set['id']);
        $this->play($user, $set['id'], []);

        /** @var array{member: list<array{puzzleId: string, failedCycles: int}>} $list */
        $list = $this->json($this->api('GET', '/api/woodpecker/sets/'.$set['id'].'/stubborn', $user));
        $stubborn = $list['member'];
        self::assertSame([$failed['puzzle']['id']], array_column($stubborn, 'puzzleId'));
        self::assertSame(2, $stubborn[0]['failedCycles']);

        $replay = $this->api('POST', '/api/puzzles/attempts', $user, ['replayOf' => $failed['puzzle']['id']]);
        self::assertSame(201, $replay->getStatusCode());
        self::assertFalse($this->attemptJson($replay)['rated']);
        self::assertSame(1, $this->getSet($user, $set['id'])['current']['played'] ?? null, 'free play does not count in the cycle');
    }

    public function testEachWoodpeckerPuzzleIsLoggedAsActivity(): void
    {
        $user = $this->createUserIn('alice@example.com');
        $set = $this->createSet($user);
        $played = $this->play($user, $set['id']);

        $this->runOutbox();

        $entry = self::getContainer()->get(LogEntryRepository::class)->findOneBySource('woodpecker_attempt', $played['id']);
        self::assertNotNull($entry);
        self::assertSame(ExerciseType::WoodpeckerPuzzle, $entry->getExerciseType());
        self::assertSame($set['id'], $entry->getMetadata()['setId']);
        self::assertSame('2026-09-28', $entry->getLocalDate()->format('Y-m-d'));
    }

    public function testArchivingIsForFinishedSets(): void
    {
        $user = $this->createUserIn('alice@example.com');
        $set = $this->createSet($user);

        self::assertSame(409, $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/archive', $user)->getStatusCode());
        $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/abandon', $user);
        self::assertTrue($this->setJson($this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/archive', $user))['archived']);

        /** @var array{member: list<mixed>} $current */
        $current = $this->json($this->api('GET', '/api/woodpecker/sets', $user));
        /** @var array{member: list<mixed>} $archived */
        $archived = $this->json($this->api('GET', '/api/woodpecker/sets?archived=true', $user));
        self::assertSame([], $current['member']);
        self::assertCount(1, $archived['member']);
    }

    /**
     * @return list<\Symfony\Component\Messenger\Envelope>
     */
    private function drainOutbox(): array
    {
        $all = [];
        do {
            $batch = [...$this->outbox()->get()];
            foreach ($batch as $envelope) {
                $this->outbox()->ack($envelope);
            }
            $all = [...$all, ...$batch];
        } while ([] !== $batch);

        return array_values($all);
    }
}
