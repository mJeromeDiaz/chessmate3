<?php

declare(strict_types=1);

namespace App\Tests\Functional\Training;

use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;

/**
 * GET /training/runs/{id}/review (docs/TRAINING.md, run review): the items of a closed run in the
 * order played, with their outcome and what a replay needs.
 *
 * @phpstan-type ReviewJson array{id: string, module: string, items: list<array{index: int, type: string, status: string, durationMs: int|null, data: array<string, mixed>}>}
 */
final class RunReviewTest extends WoodpeckerWebTestCase
{
    public function testAPuzzleRunIsReviewedOnceClosedInTheOrderPlayed(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->start($alice, ['module' => 'puzzles', 'subjectId' => $alice->getId()->toRfc4122(), 'budgetSeconds' => 300]);

        $solved = $this->playInRun($alice, $run['id'])['item'];
        $helped = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($helped);
        $response = $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $helped['id'], 'moves' => self::solution($helped['data']), 'hintLevel' => 1]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $failed = $this->playInRun($alice, $run['id'], [])['item'];
        $onScreen = $this->runNext($alice, $run['id'])['item'];
        self::assertNotNull($onScreen);

        self::assertSame(409, $this->api('GET', '/api/training/runs/'.$run['id'].'/review', $alice)->getStatusCode(), 'no review while the run is on');
        $this->travel('+1 minute');
        self::assertSame(200, $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice)->getStatusCode());

        $review = $this->review($alice, $run['id']);
        self::assertSame([$run['id'], 'puzzles'], [$review['id'], $review['module']]);
        self::assertSame(
            [[1, 'puzzle', 'ok'], [2, 'puzzle', 'hint'], [3, 'puzzle', 'fail']],
            array_map(static fn (array $item): array => [$item['index'], $item['type'], $item['status']], $review['items']),
            'the puzzle on screen at the end is not in the review',
        );
        $puzzles = array_map(static function (array $item): array {
            /** @var array{id: string, moves: list<string>} */
            return $item['data']['puzzle'];
        }, $review['items']);
        self::assertSame(
            [$solved['data']['puzzle']['id'], $helped['data']['puzzle']['id'], $failed['data']['puzzle']['id']],
            array_column($puzzles, 'id'),
        );
        self::assertSame($solved['data']['puzzle']['moves'], $puzzles[0]['moves'], 'the solution, to replay it');
        self::assertSame([1, false], [$review['items'][1]['data']['hintLevel'] ?? null, $review['items'][1]['data']['solutionShown'] ?? null]);
        self::assertNotNull($review['items'][0]['durationMs']);
    }

    public function testAWoodpeckerRunGivesThePlaceOfEachPuzzleInTheCycle(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createSet($alice);
        $run = $this->startRun($alice, $set['id']);
        $first = $this->playInRun($alice, $run['id'])['item'];
        $second = $this->playInRun($alice, $run['id'], [])['item'];
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);

        $items = $this->review($alice, $run['id'])['items'];
        self::assertSame(
            [['woodpecker_puzzle', 'ok', $first['data']['index'] + 1], ['woodpecker_puzzle', 'fail', $second['data']['index'] + 1]],
            array_map(static fn (array $item): array => [$item['type'], $item['status'], $item['data']['number'] ?? null], $items),
        );
    }

    public function testFreeStudyHasNoItemsAndAnotherUsersRunIsNotFound(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        $run = $this->start($alice, ['module' => 'free', 'subjectId' => $alice->getId()->toRfc4122(), 'budgetSeconds' => 300, 'config' => ['format' => 'book']]);
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);

        self::assertSame(['free', []], [$this->review($alice, $run['id'])['module'], $this->review($alice, $run['id'])['items']]);
        self::assertSame(404, $this->api('GET', '/api/training/runs/'.$run['id'].'/review', $bob)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/training/runs/0192f6a0-1111-7000-8000-000000000000/review', $alice)->getStatusCode());
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{id: string}
     */
    private function start(User $user, array $payload): array
    {
        $response = $this->api('POST', '/api/training/runs', $user, $payload);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{id: string} */
        return $this->json($response);
    }

    /**
     * @return ReviewJson
     */
    private function review(User $user, string $runId): array
    {
        $response = $this->api('GET', '/api/training/runs/'.$runId.'/review', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var ReviewJson */
        return $this->json($response);
    }
}
