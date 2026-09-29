<?php

declare(strict_types=1);

namespace App\Tests\Functional\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use App\Training\Event\RunCompleted;
use Doctrine\DBAL\Connection;

/**
 * Timed runs (docs/TRAINING.md), on light Woodpecker sets: time counted by the server, no item
 * after expiry, 2 s submission tolerance, lazy closing, one run per user, events.
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 */
final class TimedRunTest extends WoodpeckerWebTestCase
{
    public function testARunExpiresOnTheServerClock(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startRun($alice, $this->lightSet($alice), 120);

        self::assertSame('active', $run['status']);
        self::assertSame('2026-09-28T10:02:00+00:00', $run['expiresAt']);
        self::assertSame('2026-09-28T10:00:00+00:00', $run['serverNow']);

        $this->playInRun($alice, $run['id']);
        $this->travel('+119 seconds');
        self::assertNotNull($this->runNext($alice, $run['id'])['item'], 'Still time: an item is served.');

        $this->travel('+1 second');
        $step = $this->runNext($alice, $run['id']);
        self::assertNull($step['item'], 'Nothing is served from the expiry on.');
        self::assertSame(['closed', 'time_up', '2026-09-28T10:02:00+00:00'], [$step['run']['status'], $step['run']['closeReason'], $step['run']['closedAt']]);
        $summary = $step['run']['summary'];
        self::assertNotNull($summary);
        self::assertSame([120_000, 1, 1, 0], [$summary['durationMs'], $summary['itemCount'], $summary['successCount'], $summary['failureCount']]);
        self::assertEquals(0.5, $summary['itemsPerMinute']);
        self::assertSame(0, $this->pendingAttempts(), 'The puzzle on screen at the expiry is dropped, not counted.');
    }

    public function testASubmissionIsAcceptedWithinTheNetworkToleranceOnly(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->lightSet($alice);
        $run = $this->startRun($alice, $set, 60);
        $item = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($item);

        $this->travel('+61 seconds');
        $response = $this->runSubmit($alice, $run['id'], $item['id'], self::solution($item['data']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{run: RunJson, result: array{success: bool}} $step */
        $step = $this->json($response);
        self::assertTrue($step['result']['success']);
        self::assertSame(['closed', 'time_up', 1], [$step['run']['status'], $step['run']['closeReason'], $step['run']['summary']['itemCount'] ?? null]);

        $late = $this->startRun($alice, $set, 60);
        $item = $this->runNext($alice, $late['id'])['item'];
        self::assertNotNull($item);
        $this->travel('+63 seconds');
        self::assertSame(409, $this->runSubmit($alice, $late['id'], $item['id'], self::solution($item['data']))->getStatusCode());
        $closed = $this->getRun($alice, $late['id']);
        self::assertSame(['closed', 'time_up', 0], [$closed['status'], $closed['closeReason'], $closed['summary']['itemCount'] ?? null]);
        self::assertSame(0, $this->pendingAttempts());
    }

    public function testTheRunSummaryCountsCappedActiveTime(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startRun($alice, $this->lightSet($alice));

        foreach (['+40 seconds', '+7 minutes'] as $spent) {
            $item = $this->runNext($alice, $run['id'])['item'];
            self::assertNotNull($item);
            $this->travel($spent);
            self::assertSame(200, $this->runSubmit($alice, $run['id'], $item['id'], self::solution($item['data']))->getStatusCode());
        }
        $response = $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        $metrics = $this->getRun($alice, $run['id'])['summary']['metrics'] ?? [];
        self::assertSame([340_000, 170_000], [$metrics['activeMs'] ?? null, $metrics['averageMs'] ?? null]);
    }

    public function testReloadingGetsTheSameItemWithItsTimerRunning(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startRun($alice, $this->lightSet($alice));
        $first = $this->runNext($alice, $run['id'])['item'];
        $this->travel('+30 seconds');
        $again = $this->runNext($alice, $run['id'])['item'];

        self::assertNotNull($first);
        self::assertNotNull($again);
        self::assertSame([$first['id'], $first['data']['startedAt']], [$again['id'], $again['data']['startedAt']]);
    }

    public function testOneRunAtATimeAndAnAbandonedRunClosesLazilyAtItsExpiry(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->lightSet($alice);
        $run = $this->startRun($alice, $set, 300);
        $this->runNext($alice, $run['id']);

        self::assertSame(409, $this->api('POST', '/api/training/runs', $alice, ['module' => 'woodpecker', 'subjectId' => $set, 'budgetSeconds' => 300])->getStatusCode());
        self::assertSame($run['id'], $this->getCurrentRun($alice)['id'] ?? null);

        // The tab was closed; the user comes back an hour later.
        $this->travel('+1 hour');
        self::assertSame(404, $this->api('GET', '/api/training/runs/current', $alice)->getStatusCode());
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['closed', 'time_up', '2026-09-28T10:05:00+00:00'], [$closed['status'], $closed['closeReason'], $closed['closedAt']]);
        self::assertSame(0, $this->pendingAttempts());
        $this->startRun($alice, $set, 300);
    }

    public function testStoppingEndsTheRunForGood(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startRun($alice, $this->lightSet($alice));
        $this->playInRun($alice, $run['id']);
        $item = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($item);
        $this->travel('+90 seconds');

        $stopped = $this->json($this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice));
        self::assertSame(['closed', 'stopped', '2026-09-28T10:01:30+00:00'], [$stopped['status'], $stopped['closeReason'], $stopped['closedAt']]);
        self::assertSame(200, $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice)->getStatusCode(), 'Idempotent.');
        self::assertSame(409, $this->runSubmit($alice, $run['id'], $item['id'], self::solution($item['data']))->getStatusCode());
        self::assertNull($this->runNext($alice, $run['id'])['item']);
    }

    public function testRunsAndItemsOfOthersAreInvisible(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $mallory = $this->createUserIn('mallory@example.com');
        $set = $this->lightSet($alice);
        $run = $this->startRun($alice, $set);
        $item = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($item);

        self::assertSame(404, $this->api('POST', '/api/training/runs', $mallory, ['module' => 'woodpecker', 'subjectId' => $set, 'budgetSeconds' => 300])->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/training/runs/'.$run['id'], $mallory)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/training/runs/current', $mallory)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/training/runs/'.$run['id'].'/next', $mallory)->getStatusCode());
        self::assertSame(404, $this->runSubmit($mallory, $run['id'], $item['id'], [])->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $mallory)->getStatusCode());

        // Mallory's own run cannot resolve Alice's item.
        $malloryRun = $this->startRun($mallory, $this->lightSet($mallory));
        self::assertSame(404, $this->runSubmit($mallory, $malloryRun['id'], $item['id'], [])->getStatusCode());
        // Nor can an untimed submission.
        self::assertSame(409, $this->api('POST', '/api/woodpecker/attempts/'.$item['id'].'/submission', $alice, ['moves' => []])->getStatusCode());
    }

    public function testAnItemIsSubmittedOnceAndFromItsOwnRunOnly(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->lightSet($alice);
        $run = $this->startRun($alice, $set);
        $played = $this->playInRun($alice, $run['id']);

        self::assertSame(409, $this->runSubmit($alice, $run['id'], $played['item']['id'], self::solution($played['item']['data']))->getStatusCode());
        self::assertSame(404, $this->runSubmit($alice, $run['id'], 'not-an-id', [])->getStatusCode());
        $next = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($next);
        self::assertSame(400, $this->runSubmit($alice, $run['id'], $next['id'], ['a1a1'])->getStatusCode());
    }

    public function testInvalidStartsAreRejected(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->lightSet($alice);

        foreach ([
            ['module' => 'woodpecker', 'subjectId' => $set, 'budgetSeconds' => 59],
            ['module' => 'woodpecker', 'subjectId' => $set, 'budgetSeconds' => 3601],
            ['module' => 'chess960', 'subjectId' => $set, 'budgetSeconds' => 300],
            ['module' => 'woodpecker', 'subjectId' => 'nope', 'budgetSeconds' => 300],
        ] as $body) {
            self::assertSame(422, $this->api('POST', '/api/training/runs', $alice, $body)->getStatusCode(), (string) json_encode($body));
        }
        self::assertSame(404, $this->api('POST', '/api/training/runs', $alice, ['module' => 'woodpecker', 'subjectId' => '0192f6a0-1111-7000-8000-000000000000', 'budgetSeconds' => 300])->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/training/runs/current', $alice)->getStatusCode(), 'No run was created.');
    }

    public function testEachClosingEmitsRunCompletedOnceAndPuzzlesCarryTheRun(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->lightSet($alice);
        $this->drainOutbox();
        $run = $this->startRun($alice, $set, 300);
        $this->playInRun($alice, $run['id']);
        $this->playInRun($alice, $run['id'], []);
        $this->travel('+10 minutes');
        $this->api('GET', '/api/training/runs/current', $alice);
        $this->api('GET', '/api/training/runs/current', $alice);

        $messages = $this->drainOutbox();
        $completed = array_values(array_filter($messages, static fn (object $m): bool => $m instanceof RunCompleted));
        self::assertCount(1, $completed);
        self::assertSame(
            [$alice->getId()->toRfc4122(), $run['id'], 'woodpecker', 'woodpecker_set', $set, null, 'time_up', 300, 300_000, 2, 1, '2026-09-28T10:05:00+00:00'],
            [$completed[0]->userId, $completed[0]->runId, $completed[0]->module, $completed[0]->subjectType, $completed[0]->subjectId, $completed[0]->parentId, $completed[0]->reason, $completed[0]->budgetSeconds, $completed[0]->durationMs, $completed[0]->itemCount, $completed[0]->successCount, $completed[0]->occurredAt->format(\DATE_ATOM)],
        );
        $exercises = array_values(array_filter($messages, static fn (object $m): bool => $m instanceof ExerciseCompleted));
        self::assertCount(2, $exercises);
        self::assertSame([$run['id'], 'light'], [$exercises[0]->metadata['trainingRunId'] ?? null, $exercises[0]->metadata['mode'] ?? null]);
    }

    public function testNothingIsEmittedWhenARunCannotStart(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->lightSet($alice);
        $this->api('POST', '/api/woodpecker/sets/'.$set.'/pause', $alice);
        $this->drainOutbox();

        self::assertSame(409, $this->api('POST', '/api/training/runs', $alice, ['module' => 'woodpecker', 'subjectId' => $set, 'budgetSeconds' => 300])->getStatusCode());
        self::assertSame([], $this->drainOutbox());
        self::assertEquals(0, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM training_run'));
    }

    public function testAPausedSetClosesTheRunAtTheNextRequest(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->lightSet($alice);
        $run = $this->startRun($alice, $set);
        $this->playInRun($alice, $run['id']);
        $this->api('POST', '/api/woodpecker/sets/'.$set.'/pause', $alice);

        $step = $this->runNext($alice, $run['id']);
        self::assertNull($step['item']);
        self::assertSame(['closed', 'subject_unavailable', 1], [$step['run']['status'], $step['run']['closeReason'], $step['run']['summary']['itemCount'] ?? null]);
    }

    public function testTheSetListsItsClosedRuns(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->lightSet($alice);
        $run = $this->startRun($alice, $set, 60);
        $this->playInRun($alice, $run['id']);
        $this->travel('+2 minutes');
        $this->api('GET', '/api/training/runs/current', $alice);

        /** @var array{runs: list<array{id: string, closeReason: string, summary: array{itemCount: int}}>} $view */
        $view = $this->json($this->api('GET', '/api/woodpecker/sets/'.$set, $alice));
        self::assertSame([[$run['id'], 'time_up', 1]], array_map(static fn (array $r): array => [$r['id'], $r['closeReason'], $r['summary']['itemCount']], $view['runs']));
    }

    private function lightSet(User $user): string
    {
        $response = $this->api('POST', '/api/woodpecker/sets', $user, ['name' => 'Light', 'mode' => 'light', 'ratingMin' => 400, 'ratingMax' => 3200]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->json($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return RunJson
     */
    private function getRun(User $user, string $runId): array
    {
        $response = $this->api('GET', '/api/training/runs/'.$runId, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    /**
     * @return array<string, mixed>
     */
    private function getCurrentRun(User $user): array
    {
        $response = $this->api('GET', '/api/training/runs/current', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response);
    }

    private function pendingAttempts(): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM woodpecker_attempt WHERE status = 'pending'");

        return is_numeric($count) ? (int) $count : -1;
    }

    /**
     * @return list<object>
     */
    private function drainOutbox(): array
    {
        $messages = [];
        do {
            $batch = [...$this->outbox()->get()];
            foreach ($batch as $envelope) {
                $messages[] = $envelope->getMessage();
                $this->outbox()->ack($envelope);
            }
        } while ([] !== $batch);

        return $messages;
    }
}
