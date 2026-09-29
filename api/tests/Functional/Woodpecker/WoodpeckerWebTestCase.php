<?php

declare(strict_types=1);

namespace App\Tests\Functional\Woodpecker;

use App\Entity\User;
use App\Tests\Functional\Activity\ActivityOutboxTrait;
use App\Tests\Functional\Puzzle\PuzzleWebTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpFoundation\Response;

/**
 * Woodpecker API tests on the 50 sample puzzles (48 selectable), with a controllable clock.
 *
 * @phpstan-type CycleJson array{number: int, run: int, status: string, durationDays: int, availableAt: string, deadlineAt: string, completedAt: string|null, lostAt: string|null, played: int, solved: int, failed: int, accuracy: float|int|null, activeMs: int, averageMs: int|null, calendarMs: int|null, onTime: bool|null, daysLeft: int|null}
 * @phpstan-type SetJson array{id: string, name: string, status: string, archived: bool, puzzleCount: int, ratingMin: int, ratingMax: int, themes: list<string>, cycleCount: int, timezone: string, current: CycleJson|null, cycles: list<CycleJson>}
 * @phpstan-type RunJson array{id: string, module: string, subjectId: string, status: string, closeReason: string|null, budgetSeconds: int, startedAt: string, expiresAt: string, closedAt: string|null, serverNow: string, summary: array{durationMs: int, itemCount: int, successCount: int, failureCount: int, successRate: float|int|null, itemsPerMinute: float|int|null, metrics: array<string, mixed>, context: array<string, mixed>}|null}
 * @phpstan-type ItemJson array{id: string, type: string, data: array{puzzle: array{id: string, fen: string, moves: list<string>, playerColor: string}, mode: string, round: int, index: int, puzzleCount: int, startedAt: string}}
 * @phpstan-type StepJson array{id: string, run: RunJson, item: ItemJson|null, result: array{itemId: string, success: bool, data: array<string, mixed>}|null}
 * @phpstan-type WoodpeckerAttemptJson array{id: string, status: string, mistakes: int, puzzle: array{id: string, fen: string, moves: list<string>, playerColor: string, rating: int, themes: list<string>, gameUrl: string}, set: SetJson}
 */
abstract class WoodpeckerWebTestCase extends PuzzleWebTestCase
{
    use ActivityOutboxTrait;

    protected MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-09-28 10:00:00', 'UTC');
        Clock::set($this->clock);
    }

    protected function tearDown(): void
    {
        Clock::set(new \Symfony\Component\Clock\NativeClock());
        parent::tearDown();
    }

    protected function travel(string $modifier): void
    {
        $this->clock->modify($modifier);
    }

    protected function createUserIn(string $email, ?string $timezone = 'Europe/Paris'): User
    {
        $user = $this->createUser($email);
        $user->setTimezone($timezone);
        $this->entityManager->flush();

        return $user;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return SetJson
     */
    protected function createSet(User $user, array $options = []): array
    {
        $response = $this->api('POST', '/api/woodpecker/sets', $user, $options + [
            'name' => 'Tactics',
            'puzzleCount' => 5,
            'ratingMin' => 400,
            'ratingMax' => 3200,
            'cycleCount' => 3,
            'firstCycleDays' => 4,
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        return $this->setJson($response);
    }

    /**
     * @return SetJson
     */
    protected function setJson(Response $response): array
    {
        /** @var SetJson */
        return $this->json($response);
    }

    /**
     * @return SetJson
     */
    protected function getSet(User $user, string $setId): array
    {
        $response = $this->api('GET', '/api/woodpecker/sets/'.$setId, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->setJson($response);
    }

    /**
     * @return WoodpeckerAttemptJson
     */
    protected function next(User $user, string $setId): array
    {
        $response = $this->api('POST', '/api/woodpecker/sets/'.$setId.'/attempts', $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var WoodpeckerAttemptJson */
        return $this->json($response);
    }

    /**
     * @param list<string>|null $moves null = the solution
     *
     * @return WoodpeckerAttemptJson
     */
    protected function play(User $user, string $setId, ?array $moves = null): array
    {
        $attempt = $this->next($user, $setId);
        $response = $this->api('POST', '/api/woodpecker/attempts/'.$attempt['id'].'/submission', $user, [
            'moves' => $moves ?? self::solution($attempt),
        ]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var WoodpeckerAttemptJson */
        return $this->json($response);
    }

    /**
     * Plays every remaining puzzle of the current run, solving them.
     *
     * @return WoodpeckerAttemptJson the last submission
     */
    protected function finishRun(User $user, string $setId): array
    {
        $set = $this->getSet($user, $setId);
        self::assertNotNull($set['current']);
        $last = null;
        for ($i = $set['current']['played']; $i < $set['puzzleCount']; ++$i) {
            $last = $this->play($user, $setId);
        }
        self::assertNotNull($last);

        return $last;
    }

    /**
     * @return RunJson
     */
    protected function startRun(User $user, string $setId, int $budgetSeconds = 1200): array
    {
        $response = $this->api('POST', '/api/training/runs', $user, ['module' => 'woodpecker', 'subjectId' => $setId, 'budgetSeconds' => $budgetSeconds]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    /**
     * @return StepJson
     */
    protected function runNext(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var StepJson */
        return $this->json($response);
    }

    /**
     * @param list<string> $moves
     */
    protected function runSubmit(User $user, string $runId, string $itemId, array $moves): Response
    {
        return $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId, 'moves' => $moves]);
    }

    /**
     * Plays the next item of a run (solved unless $moves are given).
     *
     * @param list<string>|null $moves
     *
     * @return array{item: ItemJson, step: StepJson}
     */
    protected function playInRun(User $user, string $runId, ?array $moves = null): array
    {
        $item = $this->runNext($user, $runId)['item'];
        self::assertNotNull($item, 'The run served no item.');
        $response = $this->runSubmit($user, $runId, $item['id'], $moves ?? self::solution($item['data']));
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var StepJson $step */
        $step = $this->json($response);

        return ['item' => $item, 'step' => $step];
    }

    /**
     * @param array{puzzle: array{moves: list<string>}} $attempt
     *
     * @return list<string>
     */
    protected static function solution(array $attempt): array
    {
        $moves = [];
        foreach ($attempt['puzzle']['moves'] as $i => $move) {
            if (1 === $i % 2) {
                $moves[] = $move;
            }
        }

        return $moves;
    }
}
