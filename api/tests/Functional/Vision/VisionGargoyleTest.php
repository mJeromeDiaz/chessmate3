<?php

declare(strict_types=1);

namespace App\Tests\Functional\Vision;

use App\Chess\Attacks;
use App\Chess\Rules;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use App\Vision\Board\EnPrise;
use App\Vision\Board\Reach;
use App\Vision\Board\Relations;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The Gargouille, boss of chapter 3 (docs/VISION.md, § 2 quinquies): 2 positions, 5 questions on
 * each, 15 s each on the server's clock (late or not answered: a failure), 3 lives, strong
 * distractions; beaten, the chapter is done.
 *
 * @phpstan-type Item array{id: string, type: string, data: array<string, mixed>}
 */
final class VisionGargoyleTest extends WoodpeckerWebTestCase
{
    private const BEFORE_BOSS = [
        '1-1', '1-2', '1-3', '1-4', '1-chest', '1-5', '1-6', '1-7', '1-8', '1-9', '1-boss',
        '2-1-1', '2-1-2', '2-1-3', '2-1-4', '2-1-boss', '2-2-1', '2-2-2', '2-2-3', '2-2-4', '2-2-boss',
        '2-3-1', '2-3-2', '2-3-3', '2-3-4', '2-3-boss', '2-boss', '3-0-boss',
        '3-1-1', '3-1-2', '3-1-3', '3-1-4', '3-1-5', '3-1-boss',
        '3-2-1', '3-2-2', '3-2-3', '3-2-4', '3-2-5', '3-2-boss',
        '3-3-1', '3-3-2', '3-3-3', '3-3-4', '3-3-5', '3-3-boss',
    ];

    public function testTwoPositionsFiveQuestionsEachInTheirTime(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->complete($alice, self::BEFORE_BOSS);

        $response = $this->api('POST', '/api/mind-map/visualisation/steps/3-boss/start', $alice);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);
        $runId = $run['id'] ?? null;
        self::assertIsString($runId);

        $fens = [];
        for ($i = 0; $i < 10; ++$i) {
            $item = $this->item($alice, $runId);
            $data = $item['data'];
            self::assertSame(['gargoyle', 15, 'strong', 10, 0 !== $i % 5], [$data['kind'] ?? null, $data['seconds'] ?? null, $data['distraction'] ?? null, $data['count'] ?? null, $data['followUp'] ?? null]);
            $fens[] = \is_string($data['fen'] ?? null) ? $data['fen'] : '';
            if (1 === $i) {
                // 15 s and the margins past: late, though right.
                $this->travel('+20 seconds');
                $late = $this->submitOk($alice, $runId, $item['id'], self::answer($item));
                self::assertSame([false, 'late', 2], [$late['success'] ?? null, self::data($late)['status'] ?? null, self::data($late)['lives'] ?? null]);
                continue;
            }
            if (2 === $i) {
                // The time ran out on the client: nothing answered.
                $empty = $this->submitOk($alice, $runId, $item['id'], ['pass' => true]);
                self::assertSame([false, 'wrong', 1], [$empty['success'] ?? null, self::data($empty)['status'] ?? null, self::data($empty)['lives'] ?? null]);
                continue;
            }
            // The first question of a position has its memorizing and its moves before its 15 s.
            if (0 === $i % 5) {
                $this->travel('+35 seconds');
            }
            $right = $this->submitOk($alice, $runId, $item['id'], self::answer($item));
            self::assertTrue($right['success'] ?? null, (string) json_encode($data['ask'] ?? null));
        }
        self::assertSame(2, \count(array_unique($fens)), 'Two positions, five questions each.');

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['passed', 10, 8, true], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['completed'] ?? null]);
        $path = $this->json($this->api('GET', '/api/mind-map/visualisation', $alice));
        $badges = \is_array($path['badges'] ?? null) ? array_column($path['badges'], 'badge') : [];
        self::assertContains('boss_gargouille', $badges);
    }

    /**
     * The right answer to a question of the Gargouille.
     *
     * @param Item $item
     *
     * @return array<string, mixed>
     */
    private static function answer(array $item): array
    {
        $data = $item['data'];
        $fen = $data['fen'] ?? null;
        self::assertIsString($fen);
        $rules = Rules::fromFen($fen);
        foreach (\is_array($data['announced'] ?? null) ? $data['announced'] : [] as $move) {
            $rules->playUci(\is_array($move) && \is_string($move['uci'] ?? null) ? $move['uci'] : '');
        }
        $final = $rules->fen();
        $board = Attacks::board($final);
        $target = \is_string($data['target'] ?? null) ? $data['target'] : '';
        $color = 'black' === ($data['side'] ?? null) ? 'b' : 'w';

        return match ($data['ask'] ?? null) {
            'what' => ['placement' => isset($board[$target]) ? [['square' => $target, 'piece' => $board[$target]]] : []],
            'where' => ['squares' => [(string) array_search($target, $board, true)]],
            'reach' => ['choice' => Reach::canReach($final, (string) array_search($data['piece'] ?? '', $board, true), $target) ? 'yes' : 'no'],
            'attacked' => ['squares' => Relations::attacked($final, $color)],
            'aligned' => ['squares' => Relations::aligned($final, (string) array_search($target, $board, true))],
            default => ['squares' => EnPrise::squares($final)],
        };
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<string, mixed>
     */
    private static function data(array $result): array
    {
        $data = $result['data'] ?? null;
        self::assertIsArray($data);

        /** @var array<string, mixed> */
        return $data;
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

    /**
     * @return Item
     */
    private function item(User $user, string $runId): array
    {
        $item = $this->json($this->api('POST', '/api/training/runs/'.$runId.'/next', $user))['item'] ?? null;
        self::assertIsArray($item, 'The run served no question.');

        /** @var Item */
        return $item;
    }

    /**
     * @param array<string, mixed> $report
     *
     * @return array<string, mixed>
     */
    private function submitOk(User $user, string $runId, string $itemId, array $report): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId] + $report);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var array<string, mixed> */
        return $result;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
