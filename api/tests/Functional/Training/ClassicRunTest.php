<?php

declare(strict_types=1);

namespace App\Tests\Functional\Training;

use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;

/**
 * Timed runs on classic sets (docs/TRAINING.md, docs/WOODPECKER.md): a run moves the current cycle
 * on, deadlines included, and closes cleanly when the cycle ends before a rest, when the set is
 * completed, or when the set is paused meanwhile. Sets of 5 puzzles, user in Europe/Paris, clock
 * at 2026-09-28 10:00 UTC.
 */
final class ClassicRunTest extends WoodpeckerWebTestCase
{
    public function testARunMovesTheCurrentCycleOnAndHoldsTheSet(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice);
        $untimed = $this->next($alice, $set['id']);
        $this->travel('+1 minute');

        $run = $this->startRun($alice, $set['id']);
        $item = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($item);
        self::assertSame([$untimed['id'], '2026-09-28T10:01:00+00:00'], [$item['id'], $item['data']['startedAt']], 'The pending puzzle is taken over, with a fresh timer.');
        self::assertSame(200, $this->runSubmit($alice, $run['id'], $item['id'], self::solution($item['data']))->getStatusCode());
        $this->playInRun($alice, $run['id']);

        self::assertSame(409, $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/attempts', $alice)->getStatusCode(), 'The run holds the set.');
        $held = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($held);
        self::assertSame(409, $this->api('POST', '/api/woodpecker/attempts/'.$held['id'].'/submission', $alice, ['moves' => self::solution($held['data'])])->getStatusCode());

        /** @var array{summary: array{itemCount: int, metrics: array{cycle: array{number: int, run: int, played: int}}}} $stopped */
        $stopped = $this->json($this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice));
        self::assertSame(2, $stopped['summary']['itemCount']);
        self::assertSame(['number' => 1, 'run' => 1, 'played' => 2], array_intersect_key($stopped['summary']['metrics']['cycle'], array_flip(['number', 'run', 'played'])));

        $view = $this->getSet($alice, $set['id']);
        self::assertSame(2, $view['current']['played'] ?? null);
        $after = $this->next($alice, $set['id']);
        self::assertSame($held['data']['puzzle']['id'], $after['puzzle']['id'], 'The puzzle on screen was not counted: it is still the next one.');
        self::assertNotSame($held['id'], $after['id']);
    }

    public function testCompletingACycleBeforeARestClosesTheRun(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice, ['restDays' => 1]);
        $run = $this->startRun($alice, $set['id']);

        $last = null;
        for ($i = 0; $i < 5; ++$i) {
            $last = $this->playInRun($alice, $run['id'])['step'];
        }
        self::assertSame(['closed', 'subject_resting'], [$last['run']['status'], $last['run']['closeReason']]);
        $summary = $last['run']['summary'];
        self::assertNotNull($summary);
        // Completed on the 28th (Paris): one rest day, playable from the 30th at 00:00 Paris.
        self::assertSame(['availableAt' => '2026-09-29T22:00:00+00:00'], $summary['context']);
        self::assertSame(5, $summary['itemCount']);

        self::assertSame(409, $this->api('POST', '/api/training/runs', $alice, ['module' => 'woodpecker', 'subjectId' => $set['id'], 'budgetSeconds' => 600])->getStatusCode());
        $this->travel('+2 days');
        $next = $this->startRun($alice, $set['id']);
        self::assertSame(2, $this->runNext($alice, $next['id'])['item']['data']['round'] ?? null);
    }

    public function testWithoutRestTheRunGoesOnIntoTheNextCycle(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice);
        $run = $this->startRun($alice, $set['id']);
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame('active', $this->playInRun($alice, $run['id'])['step']['run']['status']);
        }

        $item = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($item);
        self::assertSame([2, 1, 0], [$item['data']['round'], $item['data']['cycleRun'], $item['data']['index']]);
    }

    public function testCompletingTheSetClosesTheRun(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice, ['cycleCount' => 2]);
        $run = $this->startRun($alice, $set['id']);

        $last = null;
        for ($i = 0; $i < 10; ++$i) {
            $last = $this->playInRun($alice, $run['id'])['step'];
        }
        self::assertSame(['closed', 'subject_finished', 10], [$last['run']['status'], $last['run']['closeReason'], $last['run']['summary']['itemCount'] ?? null]);
        self::assertSame('completed', $this->getSet($alice, $set['id'])['status']);
        self::assertSame(409, $this->api('POST', '/api/training/runs', $alice, ['module' => 'woodpecker', 'subjectId' => $set['id'], 'budgetSeconds' => 600])->getStatusCode());
    }

    public function testAPausedSetCannotStartARunAndPausingDuringOneClosesIt(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice);
        $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/pause', $alice);
        self::assertSame(409, $this->api('POST', '/api/training/runs', $alice, ['module' => 'woodpecker', 'subjectId' => $set['id'], 'budgetSeconds' => 600])->getStatusCode());
        $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/resume', $alice);

        $run = $this->startRun($alice, $set['id']);
        $this->playInRun($alice, $run['id']);
        $item = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($item);
        $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/pause', $alice);

        self::assertSame(409, $this->runSubmit($alice, $run['id'], $item['id'], self::solution($item['data']))->getStatusCode());
        $closed = $this->runNext($alice, $run['id']);
        self::assertNull($closed['item']);
        self::assertSame(['closed', 'subject_unavailable', 1], [$closed['run']['status'], $closed['run']['closeReason'], $closed['run']['summary']['itemCount'] ?? null]);
        self::assertSame(0, $this->pendingAttempts());
        self::assertSame('paused', $this->getSet($alice, $set['id'])['status']);
    }

    public function testALostCycleDuringARunGoesOnWithTheNewCycleRun(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        // One-day first cycle: deadline at the end of the 28th in Paris, 22:00 UTC.
        $set = $this->createSet($alice, ['firstCycleDays' => 1]);
        $this->travel('+11 hours 30 minutes');
        $run = $this->startRun($alice, $set['id'], 3600);
        $this->playInRun($alice, $run['id']);
        $onScreen = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($onScreen);

        $this->travel('+40 minutes');
        self::assertSame(409, $this->runSubmit($alice, $run['id'], $onScreen['id'], self::solution($onScreen['data']))->getStatusCode(), 'Its cycle run was lost.');
        $item = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($item, 'The run goes on.');
        self::assertSame([1, 2, 0], [$item['data']['round'], $item['data']['cycleRun'], $item['data']['index']]);
        self::assertSame(200, $this->runSubmit($alice, $run['id'], $item['id'], self::solution($item['data']))->getStatusCode());

        /** @var array{summary: array{itemCount: int}} $stopped */
        $stopped = $this->json($this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice));
        self::assertSame(2, $stopped['summary']['itemCount'], 'Both resolved puzzles count, in either cycle run.');
        self::assertSame(0, $this->pendingAttempts(), 'The puzzle left in the lost cycle run is dropped too.');
        self::assertSame(['lost', 'active'], array_column($this->getSet($alice, $set['id'])['cycles'], 'status'));
    }

    private function pendingAttempts(): int
    {
        $count = self::getContainer()->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM woodpecker_attempt WHERE status = 'pending'");

        return is_numeric($count) ? (int) $count : -1;
    }
}
