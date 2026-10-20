<?php

declare(strict_types=1);

namespace App\Tests\Functional\MindMap;

use App\Blindfold\Recall\Placement;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The visualisation path's chapter 2 (docs/MIND_MAP.md): its exercises are lessons of position
 * recall (docs/BLINDFOLD_RECALL.md, § 3 bis), 5 positions at a level the player may not have opened
 * yet, only exact rebuilds right; its mini-boss 2.1 rebuilds levels 2, 3 and 4 on a board without
 * coordinates, a life per piece wrong or missing.
 *
 * @phpstan-type RecallItem array{id: string, type: string, data: array{fen: string, level: int, position: int, count: int|null, lives: int|null, coordinates: bool}}
 * @phpstan-type RecallResult array{itemId: string, success: bool, data: array{status: string, position: int, count: int|null, lives: int|null, missing: int}}
 */
final class RecallPathTest extends WoodpeckerWebTestCase
{
    public function testALessonAtALevelNotOpenYet(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->completeChapterOne($alice);
        foreach (['2-1-1', '2-1-2', '2-1-3', '2-1-4', '2-1-boss'] as $step) {
            $this->completed($alice, $step);
        }

        // Level 5: never opened on the recall page.
        $runId = $this->startStep($alice, '2-2-1');
        for ($i = 1; $i <= 5; ++$i) {
            $item = $this->item($alice, $runId);
            self::assertSame([5, $i, 5, null, true], [$item['data']['level'], $item['data']['position'], $item['data']['count'], $item['data']['lives'], $item['data']['coordinates']]);
            // One position close (a piece forgotten), four exact: 80 %.
            $placement = self::placementOf($item['data']['fen']);
            $result = $this->submitOk($alice, $runId, $item['id'], 3 === $i ? \array_slice($placement, 1) : $placement);
            self::assertSame([3 !== $i, $i], [$result['success'], $result['data']['position']]);
        }

        $outcome = $this->outcome($alice, $runId);
        self::assertSame(['passed', 5, 4, 1, 1, false], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['failures'] ?? null, $outcome['passes'] ?? null, $outcome['completed'] ?? null]);
    }

    public function testTheMiniBossCountsItsPieces(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->completeSectionOne($alice);

        $runId = $this->startStep($alice, '2-1-boss');
        $levels = [];
        $missing = [0, 1, 1];
        foreach ($missing as $i => $forgotten) {
            $item = $this->item($alice, $runId);
            self::assertSame([$i + 1, 3, 3 - array_sum(\array_slice($missing, 0, $i)), false], [$item['data']['position'], $item['data']['count'], $item['data']['lives'], $item['data']['coordinates']]);
            $levels[] = $item['data']['level'];
            $result = $this->submitOk($alice, $runId, $item['id'], \array_slice(self::placementOf($item['data']['fen']), $forgotten));
            self::assertSame([$forgotten, 3 - array_sum(\array_slice($missing, 0, $i + 1))], [$result['data']['missing'], $result['data']['lives']]);
        }
        self::assertSame([2, 3, 4], $levels, 'Levels 2, 3 then 4.');

        // Two pieces forgotten in all: one life left, beaten.
        $outcome = $this->outcome($alice, $runId);
        self::assertSame(['passed', 3, 1, 2, 3, true], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['failures'] ?? null, $outcome['lives'] ?? null, $outcome['completed'] ?? null]);
    }

    public function testThreePiecesWrongLoseTheMiniBoss(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->completeSectionOne($alice);

        $runId = $this->startStep($alice, '2-1-boss');
        $item = $this->item($alice, $runId);
        // Level 2: 4 pieces, three of them forgotten.
        $result = $this->submitOk($alice, $runId, $item['id'], \array_slice(self::placementOf($item['data']['fen']), 3));
        self::assertSame(0, $result['data']['lives']);

        $run = $this->json($this->api('GET', '/api/training/runs/'.$runId, $alice));
        self::assertSame(['closed', 'lives_out'], [$run['status'] ?? null, \is_array($run['summary'] ?? null) && \is_array($run['summary']['context'] ?? null) ? $run['summary']['context']['reason'] ?? null : null]);
        $outcome = $this->outcome($alice, $runId);
        self::assertSame(['failed', 1, 3, false], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['failures'] ?? null, $outcome['completed'] ?? null]);
    }

    private function completeChapterOne(User $user): void
    {
        foreach (['1-1', '1-2', '1-3', '1-4', '1-chest', '1-5', '1-6', '1-7', '1-8', '1-9', '1-boss'] as $step) {
            $this->completed($user, $step);
        }
    }

    private function completeSectionOne(User $user): void
    {
        $this->completeChapterOne($user);
        foreach (['2-1-1', '2-1-2', '2-1-3', '2-1-4'] as $step) {
            $this->completed($user, $step);
        }
    }

    private function startStep(User $user, string $step): string
    {
        $response = $this->api('POST', '/api/mind-map/visualisation/steps/'.$step.'/start', $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);
        self::assertSame('blindfold_recall', $run['module'] ?? null);
        $id = $run['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return RecallItem
     */
    private function item(User $user, string $runId): array
    {
        $item = $this->json($this->api('POST', '/api/training/runs/'.$runId.'/next', $user))['item'] ?? null;
        self::assertIsArray($item, 'The run served no position.');

        /** @var RecallItem */
        return $item;
    }

    /**
     * @param list<array{square: string, piece: string}> $placement
     *
     * @return RecallResult
     */
    private function submitOk(User $user, string $runId, string $itemId, array $placement): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId, 'placement' => $placement]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var RecallResult */
        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(User $user, string $runId): array
    {
        $response = $this->api('GET', '/api/mind-map/runs/'.$runId, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response);
    }

    /**
     * @return list<array{square: string, piece: string}>
     */
    private static function placementOf(string $fen): array
    {
        $placed = [];
        foreach (Placement::squares($fen) as $square => $piece) {
            $placed[] = ['square' => $square, 'piece' => $piece];
        }

        return $placed;
    }

    /** A step set as done. */
    private function completed(User $user, string $step): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, 'visualisation', ?, 2, '2026-09-28 09:00:00', '2026-09-28 09:00:00')",
            [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $step],
        );
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
