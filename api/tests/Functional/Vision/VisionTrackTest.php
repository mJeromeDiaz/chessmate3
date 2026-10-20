<?php

declare(strict_types=1);

namespace App\Tests\Functional\Vision;

use App\Chess\Attacks;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use App\Vision\Drawing\TrackDrawer;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The section 3.1 of the visualisation path (docs/VISION.md, § 2 bis): a piece's path, written as it
 * moves, then a question on it (the piece it took, a knight's fewest jumps); the mini-boss 3.1 asks
 * three questions on each path, the path shown once; the distractions a boss passes on.
 *
 * @phpstan-type TrackItem array{id: string, type: string, data: array{kind: string, ask: string, fen: string, visibleSeconds: int, announced: list<array{uci: string, san: string}>, announcedStyle: string, target: string|null, piece: string|null, to: string|null, choices: list<string>|null, followUp: bool, distraction: string, position: int, count: int|null, lives: int|null}}
 * @phpstan-type TrackResult array{itemId: string, success: bool, data: array{status: string, expected: list<string>, answer: list<string>, finalFen: string, lives: int|null}}
 */
final class VisionTrackTest extends WoodpeckerWebTestCase
{
    private const BEFORE_SECTION = [
        '1-1', '1-2', '1-3', '1-4', '1-chest', '1-5', '1-6', '1-7', '1-8', '1-9', '1-boss',
        '2-1-1', '2-1-2', '2-1-3', '2-1-4', '2-1-boss', '2-2-1', '2-2-2', '2-2-3', '2-2-4', '2-2-boss',
        '2-3-1', '2-3-2', '2-3-3', '2-3-4', '2-3-boss', '2-boss', '3-0-boss',
    ];

    public function testThePieceTakenOnAPath(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->complete($alice, self::BEFORE_SECTION);

        $runId = $this->startStep($alice, '3-1-1');
        $item = $this->item($alice, $runId);
        $data = $item['data'];
        self::assertSame(['track_capture', 'captured', 'path', 8, false, 'none'], [$data['kind'], $data['ask'], $data['announcedStyle'], $data['visibleSeconds'], $data['followUp'], $data['distraction']]);
        self::assertContains($data['piece'], ['R', 'B']);
        $last = $data['announced'][\count($data['announced']) - 1];
        self::assertSame($data['target'], substr($last['uci'], 2, 2), 'The last move takes, on the square asked.');
        self::assertStringContainsString('x', $last['san']);

        $taken = Attacks::board($data['fen'])[(string) $data['target']] ?? '';
        $right = $this->submitOk($alice, $runId, $item['id'], ['placement' => [['square' => $data['target'], 'piece' => $taken]]]);
        self::assertSame([true, [$taken]], [$right['success'], $right['data']['expected']]);
        self::assertSame($data['piece'], Attacks::board($right['data']['finalFen'])[(string) $data['target']] ?? null, 'The piece stands where it took.');
    }

    public function testAKnightsJumpsAreAChoice(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->complete($alice, [...self::BEFORE_SECTION, '3-1-1', '3-1-2']);

        $runId = $this->startStep($alice, '3-1-3');
        $item = $this->item($alice, $runId);
        self::assertSame(['knight_distance', 'knight_jumps', 0, [], ['1', '2', '3', '4', '5', '6']], [$item['data']['kind'], $item['data']['ask'], $item['data']['visibleSeconds'], $item['data']['announced'], $item['data']['choices']]);
        self::assertSame(400, $this->submit($alice, $runId, $item['id'], [])->getStatusCode(), 'A choice is expected.');
        $jumps = (string) TrackDrawer::knightDistance((string) $item['data']['target'], (string) $item['data']['to']);
        self::assertTrue($this->submitOk($alice, $runId, $item['id'], ['choice' => $jumps])['success']);
        $item = $this->item($alice, $runId);
        $wrong = (string) (TrackDrawer::knightDistance((string) $item['data']['target'], (string) $item['data']['to']) % 6 + 1);
        self::assertFalse($this->submitOk($alice, $runId, $item['id'], ['choice' => $wrong])['success']);
    }

    public function testTheMiniBossAsksThreeQuestionsOnEachPath(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->complete($alice, [...self::BEFORE_SECTION, '3-1-1', '3-1-2', '3-1-3', '3-1-4', '3-1-5']);

        $runId = $this->startStep($alice, '3-1-boss');
        for ($path = 0; $path < 3; ++$path) {
            $first = null;
            foreach (['arrival', 'captures', 'stops'] as $step => $ask) {
                $item = $this->item($alice, $runId);
                $data = $item['data'];
                self::assertSame([$ask, 0 < $step, 'medium', 9, $path * 3 + $step + 1], [$data['ask'], $data['followUp'], $data['distraction'], $data['count'], $data['position']]);
                $first ??= $data;
                if ($step > 0) {
                    self::assertSame([$first['fen'], $first['announced'], 0], [$data['fen'], $data['announced'], $data['visibleSeconds']], 'The same path, shown once.');
                }
                $tos = array_map(static fn (array $m): string => substr($m['uci'], 2, 2), $data['announced']);
                $squares = match ($ask) {
                    'arrival' => [$tos[\count($tos) - 1]],
                    'captures' => array_values(array_unique(array_map(static fn (array $m): string => substr($m['uci'], 2, 2), array_filter($data['announced'], static fn (array $m): bool => str_contains($m['san'], 'x'))))),
                    default => array_values(array_unique($tos)),
                };
                sort($squares);
                $result = $this->submitOk($alice, $runId, $item['id'], ['squares' => $squares]);
                self::assertTrue($result['success'], $ask);
                self::assertSame($squares, $result['data']['expected']);
            }
        }

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['passed', 9, 9, true], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['completed'] ?? null]);
    }

    /**
     * @param list<string> $steps
     */
    private function complete(User $user, array $steps): void
    {
        foreach ($steps as $step) {
            $this->connection()->executeStatement(
                "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, 'visualisation', ?, 4, '2026-09-28 09:00:00', '2026-09-28 09:00:00')",
                [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $step],
            );
        }
    }

    private function startStep(User $user, string $step): string
    {
        $response = $this->api('POST', '/api/mind-map/visualisation/steps/'.$step.'/start', $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);
        $id = $run['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return TrackItem
     */
    private function item(User $user, string $runId): array
    {
        $item = $this->json($this->api('POST', '/api/training/runs/'.$runId.'/next', $user))['item'] ?? null;
        self::assertIsArray($item, 'The run served no question.');

        /** @var TrackItem */
        return $item;
    }

    /**
     * @param array<string, mixed> $report
     */
    private function submit(User $user, string $runId, string $itemId, array $report): Response
    {
        return $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId] + $report);
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return TrackResult
     */
    private function submitOk(User $user, string $runId, string $itemId, array $report): array
    {
        $response = $this->submit($user, $runId, $itemId, $report);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var TrackResult */
        return $result;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
