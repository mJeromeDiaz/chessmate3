<?php

declare(strict_types=1);

namespace App\Tests\Functional\Vision;

use App\Blindfold\Recall\Placement;
use App\Chess\Attacks;
use App\Chess\Rules;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use App\Vision\Board\EnPrise;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The section 3.2 of the visualisation path (docs/VISION.md, § 2 ter): a position updated by moves
 * of both sides, rebuilt (3.2.1, position recall's `announce`), or asked about (the piece gone);
 * the mini-boss 3.2 asks three questions on each position, the last one about a piece en prise.
 *
 * @phpstan-type Item array{id: string, type: string, data: array<string, mixed>}
 */
final class VisionUpdateTest extends WoodpeckerWebTestCase
{
    private const BEFORE_SECTION = [
        '1-1', '1-2', '1-3', '1-4', '1-chest', '1-5', '1-6', '1-7', '1-8', '1-9', '1-boss',
        '2-1-1', '2-1-2', '2-1-3', '2-1-4', '2-1-boss', '2-2-1', '2-2-2', '2-2-3', '2-2-4', '2-2-boss',
        '2-3-1', '2-3-2', '2-3-3', '2-3-4', '2-3-boss', '2-boss', '3-0-boss',
        '3-1-1', '3-1-2', '3-1-3', '3-1-4', '3-1-5', '3-1-boss',
    ];

    public function testAPositionRebuiltAfterAMove(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->complete($alice, self::BEFORE_SECTION);

        $runId = $this->startStep($alice, '3-2-1', 'blindfold_recall');
        $item = $this->item($alice, $runId);
        self::assertSame([4, 5, 1], [$item['data']['level'] ?? null, $item['data']['count'] ?? null, \is_array($item['data']['announced'] ?? null) ? \count($item['data']['announced']) : 0]);
        $reached = self::reached(self::text($item['data']['fen'] ?? null), $item['data']['announced']);
        $placement = [];
        foreach (Placement::squares($reached) as $square => $piece) {
            $placement[] = ['square' => $square, 'piece' => $piece];
        }
        $result = $this->submitOk($alice, $runId, $item['id'], ['placement' => $placement]);
        self::assertSame([true, 'exact'], [$result['success'] ?? null, \is_array($result['data'] ?? null) ? $result['data']['status'] ?? null : null]);
    }

    public function testThePieceGone(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->complete($alice, [...self::BEFORE_SECTION, '3-2-1', '3-2-2']);

        $runId = $this->startStep($alice, '3-2-3', 'vision');
        $item = $this->item($alice, $runId);
        self::assertSame(['update_missing', 'missing', 'game', 10], [$item['data']['kind'] ?? null, $item['data']['ask'] ?? null, $item['data']['announcedStyle'] ?? null, $item['data']['visibleSeconds'] ?? null]);
        $before = array_count_values(Attacks::board(self::text($item['data']['fen'] ?? null)));
        $after = array_count_values(Attacks::board(self::reached(self::text($item['data']['fen'] ?? null), $item['data']['announced'])));
        $gone = array_keys(array_filter($before, static fn (int $n, string $piece): bool => $n > ($after[$piece] ?? 0), \ARRAY_FILTER_USE_BOTH));
        self::assertCount(1, $gone);
        self::assertSame(400, $this->api('POST', '/api/training/runs/'.$runId.'/submission', $alice, ['itemId' => $item['id']])->getStatusCode(), 'A choice is expected.');
        self::assertTrue($this->submitOk($alice, $runId, $item['id'], ['choice' => (string) $gone[0]])['success'] ?? null);
    }

    public function testTheMiniBossAsksThreeQuestionsOnEachPosition(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->complete($alice, [...self::BEFORE_SECTION, '3-2-1', '3-2-2', '3-2-3', '3-2-4', '3-2-5']);

        $runId = $this->startStep($alice, '3-2-boss', 'vision');
        for ($position = 0; $position < 3; ++$position) {
            $first = null;
            for ($step = 0; $step < 3; ++$step) {
                $item = $this->item($alice, $runId);
                $data = $item['data'];
                self::assertSame([0 < $step, 'medium'], [$data['followUp'] ?? null, $data['distraction'] ?? null]);
                $first ??= $data;
                self::assertSame($first['fen'], $data['fen'] ?? null, 'The same position.');
                $final = self::reached(self::text($data['fen'] ?? null), $data['announced']);
                $board = Attacks::board($final);
                // The en prise question has no target.
                $target = \is_string($data['target'] ?? null) ? $data['target'] : '';
                $answer = match ($data['ask'] ?? null) {
                    'what' => ['placement' => isset($board[$target]) ? [['square' => $target, 'piece' => $board[$target]]] : []],
                    'where' => ['squares' => [(string) array_search($target, $board, true)]],
                    default => ['squares' => [EnPrise::squares($final)[0] ?? '']],
                };
                self::assertSame(2 === $step ? 'en_prise' : null, 2 === $step ? $data['ask'] : null);
                self::assertTrue($this->submitOk($alice, $runId, $item['id'], $answer)['success'] ?? null, (string) json_encode($data['ask'] ?? null));
            }
        }

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['passed', 9, 9, true], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['completed'] ?? null]);
    }

    /**
     * The position $moves reach from $fen.
     */
    private static function reached(string $fen, mixed $moves): string
    {
        $rules = Rules::fromFen($fen);
        foreach (\is_array($moves) ? $moves : [] as $move) {
            $uci = \is_array($move) && \is_string($move['uci'] ?? null) ? $move['uci'] : '';
            self::assertNotNull($rules->playUci($uci), $uci);
        }

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

    private function startStep(User $user, string $step, string $module): string
    {
        $response = $this->api('POST', '/api/mind-map/visualisation/steps/'.$step.'/start', $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);
        self::assertSame($module, $run['module'] ?? null);
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
        self::assertIsArray($item, 'The run served no item.');

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

    private static function text(mixed $value): string
    {
        self::assertIsString($value);

        return $value;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
