<?php

declare(strict_types=1);

namespace App\Tests\Functional\Vision;

use App\Chess\Attacks;
use App\Chess\Rules;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use App\Vision\Board\EnPrise;
use App\Vision\Board\Relations;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The section 3.3 of the visualisation path (docs/VISION.md, § 2 quater): every piece in a relation
 * clicked, nothing more; the mini-boss 3.3 asks attacked, defended, then the winning captures.
 *
 * @phpstan-type Item array{id: string, type: string, data: array<string, mixed>}
 */
final class VisionRelationTest extends WoodpeckerWebTestCase
{
    private const BEFORE_SECTION = [
        '1-1', '1-2', '1-3', '1-4', '1-chest', '1-5', '1-6', '1-7', '1-8', '1-9', '1-boss',
        '2-1-1', '2-1-2', '2-1-3', '2-1-4', '2-1-boss', '2-2-1', '2-2-2', '2-2-3', '2-2-4', '2-2-boss',
        '2-3-1', '2-3-2', '2-3-3', '2-3-4', '2-3-boss', '2-boss', '3-0-boss',
        '3-1-1', '3-1-2', '3-1-3', '3-1-4', '3-1-5', '3-1-boss',
        '3-2-1', '3-2-2', '3-2-3', '3-2-4', '3-2-5', '3-2-boss',
    ];

    public function testThePiecesAttackedEveryOneNothingMore(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->complete($alice, self::BEFORE_SECTION);

        $runId = $this->startStep($alice, '3-3-1');
        $item = $this->item($alice, $runId);
        self::assertSame(['rel_attacked', 'attacked'], [$item['data']['kind'] ?? null, $item['data']['ask'] ?? null]);
        $attacked = Relations::attacked(self::reached($item), 'black' === ($item['data']['side'] ?? null) ? 'b' : 'w');
        self::assertNotSame([], $attacked);
        $wrong = $this->submitOk($alice, $runId, $item['id'], ['squares' => \array_slice($attacked, 1)]);
        self::assertSame([false, $attacked], [$wrong['success'] ?? null, \is_array($wrong['data'] ?? null) ? $wrong['data']['expected'] ?? null : null], 'One forgotten: wrong.');
        $item = $this->item($alice, $runId);
        $attacked = Relations::attacked(self::reached($item), 'black' === ($item['data']['side'] ?? null) ? 'b' : 'w');
        self::assertTrue($this->submitOk($alice, $runId, $item['id'], ['squares' => array_reverse($attacked)])['success'] ?? null, 'In any order.');
    }

    public function testTheMiniBossTellsAttackedFromWinningCaptures(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->complete($alice, [...self::BEFORE_SECTION, '3-3-1', '3-3-2', '3-3-3', '3-3-4', '3-3-5']);

        $runId = $this->startStep($alice, '3-3-boss');
        for ($position = 0; $position < 3; ++$position) {
            foreach (['attacked', 'defended', 'winning'] as $step => $ask) {
                $item = $this->item($alice, $runId);
                self::assertSame([$ask, 0 < $step, 'medium'], [$item['data']['ask'] ?? null, $item['data']['followUp'] ?? null, $item['data']['distraction'] ?? null]);
                $final = self::reached($item);
                $color = 'black' === ($item['data']['side'] ?? null) ? 'b' : 'w';
                $squares = match ($ask) {
                    'attacked' => Relations::attacked($final, $color),
                    'defended' => Relations::defended($final, $color),
                    default => EnPrise::squares($final),
                };
                self::assertTrue($this->submitOk($alice, $runId, $item['id'], ['squares' => $squares])['success'] ?? null, $ask);
            }
        }

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['passed', 9, 9, true], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['completed'] ?? null]);
    }

    /**
     * The position the item's moves reach.
     *
     * @param Item $item
     */
    private static function reached(array $item): string
    {
        $fen = $item['data']['fen'] ?? null;
        self::assertIsString($fen);
        $rules = Rules::fromFen($fen);
        $moves = $item['data']['announced'] ?? [];
        foreach (\is_array($moves) ? $moves : [] as $move) {
            $uci = \is_array($move) && \is_string($move['uci'] ?? null) ? $move['uci'] : '';
            self::assertNotNull($rules->playUci($uci), $uci);
        }
        self::assertNotEmpty(Attacks::board($rules->fen()));

        return $rules->fen();
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
