<?php

declare(strict_types=1);

namespace App\Tests\Functional\Training;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;

/**
 * The puzzles module in timed runs (docs/TRAINING.md): rated puzzles around the user's rating,
 * themes, the rating moving as in free play, and the pending puzzle never skipped (validated
 * 2026-10-04: the free-play pending attempt opens the run, the one on screen at the end stays
 * pending, free play cannot touch a run's puzzle).
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 */
final class PuzzleRunTest extends WoodpeckerWebTestCase
{
    public function testARunPlaysRatedPuzzlesAndSummarizesTheRatingChange(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startPuzzleRun($alice, 300);
        self::assertSame(['puzzles', $alice->getId()->toRfc4122()], [$run['module'], $run['subjectId']]);

        $first = $this->playInRun($alice, $run['id']);
        self::assertSame('puzzle', $first['item']['type']);
        $result = $first['step']['result'];
        self::assertNotNull($result);
        self::assertTrue($result['success']);
        self::assertGreaterThan(0, $result['data']['ratingDelta']);
        $this->playInRun($alice, $run['id'], []);

        $this->travel('+2 minutes');
        $summary = $this->stop($alice, $run['id'])['summary'];
        self::assertNotNull($summary);
        self::assertSame([2, 1, 1], [$summary['itemCount'], $summary['successCount'], $summary['failureCount']]);
        $metrics = $summary['metrics'];
        $rating = $this->rating($alice);
        self::assertSame(2, $rating['ratedCount']);
        self::assertEquals(1500, $metrics['ratingBefore'] ?? null);
        self::assertEquals(round($rating['rating']), $metrics['ratingAfter'] ?? null);
        self::assertEqualsWithDelta($rating['rating'] - 1500, $metrics['ratingDelta'] ?? null, 1.0);
    }

    public function testThemesRestrictThePuzzlesAndUnknownOnesAreRefused(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        self::assertSame(422, $this->api('POST', '/api/training/runs', $alice, $this->payload($alice, ['themes' => ['nope']]))->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/training/runs', $alice, $this->payload($alice, ['level' => 3]))->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/training/runs', $alice, $this->payload($alice, ['themes' => array_fill(0, 11, 'fork')]))->getStatusCode());

        $run = $this->startPuzzleRun($alice, 300, ['themes' => ['fork']]);
        for ($i = 0; $i < 3; ++$i) {
            $played = $this->playInRun($alice, $run['id']);
            self::assertContains('fork', $this->puzzles[$played['item']['data']['puzzle']['id']]->getThemes());
        }
    }

    public function testAnotherUsersSubjectIsNotFound(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');

        $response = $this->api('POST', '/api/training/runs', $alice, ['module' => 'puzzles', 'subjectId' => $bob->getId()->toRfc4122(), 'budgetSeconds' => 300]);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testThePendingFreePlayPuzzleOpensTheRun(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $pending = $this->startAttempt($alice);

        $run = $this->startPuzzleRun($alice, 300, ['themes' => ['mateIn1']]);
        $item = $this->runNext($alice, $run['id'])['item'];
        self::assertSame($pending['id'], $item['id'] ?? null, 'Starting a run never skips the pending puzzle.');
    }

    public function testThePuzzleOnScreenAtTheEndStaysPendingAndComesBack(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startPuzzleRun($alice, 60);
        $this->playInRun($alice, $run['id']);
        $onScreen = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($onScreen);

        // Free play cannot take the run's puzzle while the run is on.
        self::assertSame(409, $this->api('POST', '/api/puzzles/attempts', $alice, [])->getStatusCode());
        self::assertSame(409, $this->api('POST', '/api/puzzles/attempts/'.$onScreen['id'].'/submission', $alice, ['moves' => []])->getStatusCode());

        $this->travel('+2 minutes');
        $closed = $this->runNext($alice, $run['id']);
        self::assertNull($closed['item']);
        self::assertSame(['time_up', 1], [$closed['run']['closeReason'], $closed['run']['summary']['itemCount'] ?? null], 'The puzzle on screen is not counted.');

        // Not skipped: free play (after the lazy closing) serves it again, still pending.
        $again = $this->startAttempt($alice);
        self::assertSame([$onScreen['id'], 'pending'], [$again['id'], $again['status']]);
        self::assertNull($this->trainingRunOf($again['id']));
    }

    public function testStoppingAlsoKeepsThePuzzleOnScreenForTheNextRun(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startPuzzleRun($alice, 300);
        $onScreen = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($onScreen);
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);

        $next = $this->startPuzzleRun($alice, 300);
        self::assertSame($onScreen['id'], $this->runNext($alice, $next['id'])['item']['id'] ?? null);
        self::assertSame($next['id'], $this->trainingRunOf($onScreen['id']));
    }

    public function testNoPuzzleLeftRefusesTheRun(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->catalog->getConnection()->executeStatement('UPDATE puzzle SET selectable = 0');

        self::assertSame(409, $this->api('POST', '/api/training/runs', $alice, $this->payload($alice))->getStatusCode());
        self::assertEquals(0, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM training_run'));
    }

    public function testExercisesCarryTheirRun(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startPuzzleRun($alice, 300);
        $this->takeOutboxMessages();

        $this->playInRun($alice, $run['id']);

        $exercises = array_values(array_filter($this->takeOutboxMessages(), static fn (object $m): bool => $m instanceof ExerciseCompleted));
        self::assertCount(1, $exercises);
        self::assertSame(['puzzle_rated', $run['id']], [$exercises[0]->type->value, $exercises[0]->metadata['trainingRunId'] ?? null]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function payload(User $user, array $config = [], int $budgetSeconds = 300): array
    {
        return ['module' => 'puzzles', 'subjectId' => $user->getId()->toRfc4122(), 'budgetSeconds' => $budgetSeconds, 'config' => $config];
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return RunJson
     */
    private function startPuzzleRun(User $user, int $budgetSeconds, array $config = []): array
    {
        $response = $this->api('POST', '/api/training/runs', $user, $this->payload($user, $config, $budgetSeconds));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    /**
     * @return array{rating: float|int, ratedCount: int}
     */
    private function rating(User $user): array
    {
        /** @var array{rating: float|int, ratedCount: int} */
        return $this->json($this->api('GET', '/api/puzzles/rating', $user));
    }

    /**
     * @return RunJson
     */
    private function stop(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/stop', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    private function trainingRunOf(string $attemptId): ?string
    {
        $id = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT training_run_id FROM puzzle_attempt WHERE id = UUID_TO_BIN(?)',
            [$attemptId],
        );

        return \is_string($id) ? \Symfony\Component\Uid\Uuid::fromBinary($id)->toRfc4122() : null;
    }
}
