<?php

declare(strict_types=1);

namespace App\Tests\Functional\Vision;

use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The Compteur, boss of chapter 2 of the calculation path (docs/VISION.md, § 2 sexies): a long
 * puzzle's position kept in sight, 8 to 10 half-moves announced, 3 questions on where they end
 * (what, where, how many), 3 positions, 15 s each, 3 lives.
 *
 * @phpstan-type Item array{id: string, type: string, data: array<string, mixed>}
 */
final class VisionCounterTest extends WoodpeckerWebTestCase
{
    public function testThreePositionsThreeQuestionsEach(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        // The Métronome beaten: chapter 2 and its boss open.
        $this->connection()->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, 'calculation', '1-boss', 1, '2026-09-28 09:00:00', '2026-09-28 09:00:00')",
            [Uuid::v7()->toBinary(), $alice->getId()->toBinary()],
        );
        $response = $this->api('POST', '/api/mind-map/calculation/steps/2-boss/start', $alice);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);
        $runId = $run['id'] ?? null;
        self::assertIsString($runId);

        $asks = [];
        for ($i = 0; $i < 9; ++$i) {
            $item = $this->item($alice, $runId);
            $data = $item['data'];
            self::assertSame(['counter', true, 15, 9, 0 !== $i % 3], [$data['kind'] ?? null, $data['keepShown'] ?? null, $data['seconds'] ?? null, $data['count'] ?? null, $data['followUp'] ?? null]);
            if (0 === $i % 3) {
                self::assertIsArray($data['announced'] ?? null);
                self::assertGreaterThanOrEqual(8, \count($data['announced']), 'A long line.');
            }
            $asks[] = \is_string($data['ask'] ?? null) ? $data['ask'] : '';
            if ('count' === ($data['ask'] ?? null)) {
                self::assertIsArray($data['choices'] ?? null);
                self::assertCount(6, $data['choices']);
            }
            $result = $this->submitOk($alice, $runId, $item['id'], $this->answer($item));
            self::assertTrue($result['success'] ?? null, (string) json_encode($data));
        }
        $counted = array_count_values($asks);
        ksort($counted);
        self::assertSame(['count' => 3, 'what' => 3, 'where' => 3], $counted, 'Each kind once per position.');

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['passed', 9, 9, true], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['completed'] ?? null]);
        self::assertSame(240, \is_array($outcome['chest'] ?? null) ? $outcome['chest']['crystals'] ?? null : null);
    }

    /**
     * The right answer, from what the server keeps.
     *
     * @param Item $item
     *
     * @return array<string, mixed>
     */
    private function answer(array $item): array
    {
        $json = $this->connection()->fetchOne('SELECT expected FROM vision_question WHERE id = ?', [Uuid::fromString($item['id'])->toBinary()]);
        self::assertIsString($json);
        $expected = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($expected);
        $first = \is_string($expected[0] ?? null) ? $expected[0] : '';

        return match ($item['data']['ask'] ?? null) {
            'where' => ['squares' => [$first]],
            'what' => ['placement' => '' === $first ? [] : [['square' => $item['data']['target'], 'piece' => $first]]],
            default => ['choice' => $first],
        };
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
