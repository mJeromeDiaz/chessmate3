<?php

declare(strict_types=1);

namespace App\Tests\Functional\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;

/**
 * The free study module in timed runs (docs/TRAINING.md): a server-timed run with nothing to
 * submit, whose real duration is logged as a free_study exercise when it closes.
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 */
final class FreeRunTest extends WoodpeckerWebTestCase
{
    public function testAStoppedRunLogsItsRealDuration(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startFreeRun($alice, ['format' => 'book', 'notes' => 'Chapitre 3, finales de tours']);
        $this->takeOutboxMessages();

        $item = $this->runNext($alice, $run['id'])['item'];
        self::assertSame(['free_timer', $run['id']], [$item['type'] ?? null, $item['id'] ?? null]);
        self::assertSame(['format' => 'book', 'notes' => 'Chapitre 3, finales de tours'], $item['data'] ?? null);
        self::assertSame(400, $this->runSubmit($alice, $run['id'], $run['id'], [])->getStatusCode(), 'Nothing to submit.');

        $this->travel('+12 minutes 30 seconds');
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        $stopped = $this->getRun($alice, $run['id']);
        self::assertSame(['stopped', 750_000, 0], [$stopped['closeReason'], $stopped['summary']['durationMs'] ?? null, $stopped['summary']['itemCount'] ?? null]);
        self::assertEquals(['format' => 'book', 'notes' => 'Chapitre 3, finales de tours'], $stopped['summary']['metrics'] ?? null);

        $exercises = $this->exercises();
        self::assertCount(1, $exercises);
        self::assertSame(
            ['free_study', true, 750_000, 1, 'training_run', $run['id'], '2026-09-28T10:12:30+00:00', ['format' => 'book', 'trainingRunId' => $run['id']]],
            [$exercises[0]->type->value, $exercises[0]->success, $exercises[0]->durationMs, $exercises[0]->itemCount, $exercises[0]->sourceType, $exercises[0]->sourceId, $exercises[0]->occurredAt->format(\DATE_ATOM), $exercises[0]->metadata],
        );
    }

    public function testAnAbandonedRunCountsUpToItsExpiryOnly(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startFreeRun($alice, ['format' => 'video'], 600);
        $this->takeOutboxMessages();

        $this->travel('+3 hours');
        $closed = $this->getRun($alice, $run['id']);

        self::assertSame(['time_up', 600_000], [$closed['closeReason'], $closed['summary']['durationMs'] ?? null]);
        $exercises = $this->exercises();
        self::assertCount(1, $exercises);
        self::assertSame([600_000, '2026-09-28T10:10:00+00:00'], [$exercises[0]->durationMs, $exercises[0]->occurredAt->format(\DATE_ATOM)]);
    }

    public function testAnImmediateStopLogsNothing(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startFreeRun($alice, ['format' => 'other']);
        $this->takeOutboxMessages();

        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);

        self::assertSame([], $this->exercises());
    }

    public function testTheOptionsAreValidated(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        foreach ([[], ['format' => 'novel'], ['format' => 'book', 'notes' => str_repeat('é', 501)], ['format' => 'book', 'notes' => 3], ['format' => 'book', 'mood' => 'zen']] as $config) {
            $response = $this->api('POST', '/api/training/runs', $alice, $this->payload($alice, $config));
            self::assertSame(422, $response->getStatusCode(), json_encode($config, \JSON_THROW_ON_ERROR));
        }
        $this->startFreeRun($alice, ['format' => 'podcast', 'notes' => str_repeat('é', 500)]);
    }

    public function testAnotherUsersSubjectIsNotFound(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');

        $response = $this->api('POST', '/api/training/runs', $alice, ['module' => 'free', 'subjectId' => $bob->getId()->toRfc4122(), 'budgetSeconds' => 300, 'config' => ['format' => 'book']]);
        self::assertSame(404, $response->getStatusCode());
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
     * @return list<ExerciseCompleted>
     */
    private function exercises(): array
    {
        return array_values(array_filter($this->takeOutboxMessages(), static fn (object $m): bool => $m instanceof ExerciseCompleted));
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function payload(User $user, array $config, int $budgetSeconds = 1800): array
    {
        return ['module' => 'free', 'subjectId' => $user->getId()->toRfc4122(), 'budgetSeconds' => $budgetSeconds, 'config' => $config];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return RunJson
     */
    private function startFreeRun(User $user, array $config, int $budgetSeconds = 1800): array
    {
        $response = $this->api('POST', '/api/training/runs', $user, $this->payload($user, $config, $budgetSeconds));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }
}
