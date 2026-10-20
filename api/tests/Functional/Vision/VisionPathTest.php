<?php

declare(strict_types=1);

namespace App\Tests\Functional\Vision;

use App\Chess\Attacks;
use App\Entity\Catalog\Puzzle;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The vision module (docs/VISION.md) as the mind map plays it: the mini-boss 2.2 (where is a
 * piece, what stands on a square), the mini-boss 2.3 (a piece en prise after 3 moves announced) and
 * the hidden one (the attackers of a square); the server's verdicts, the lives, the XP; never
 * started outside a path.
 *
 * @phpstan-type VisionItem array{id: string, type: string, data: array{kind: string, ask: string, fen: string, visibleSeconds: int, hiddenSeconds: int, announced: list<array{uci: string, san: string}>, target: string|null, side: string|null, position: int, count: int|null, lives: int|null}}
 * @phpstan-type VisionResult array{itemId: string, success: bool, data: array{status: string, expected: list<string>, answer: list<string>, finalFen: string, position: int, count: int|null, lives: int|null}}
 */
final class VisionPathTest extends WoodpeckerWebTestCase
{
    /** 12 pieces; after Nd4, then Nd5 Ne2+ Kh1 announced, Black's Bc5 can take the undefended f2 pawn. */
    private const PUZZLE_FEN = '5rk1/5pp1/2n5/2b5/2B5/2N5/5PP1/R5K1 b - - 0 1';
    private const PUZZLE_MOVES = 'c6d4 c3d5 d4e2 g1h1';
    private const CHAPTER_ONE = ['1-1', '1-2', '1-3', '1-4', '1-chest', '1-5', '1-6', '1-7', '1-8', '1-9', '1-boss'];

    public function testWhereIsAPieceAndWhatStandsOnASquare(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->complete($alice, [...self::CHAPTER_ONE, '2-1-1', '2-1-2', '2-1-3', '2-1-4', '2-1-boss', '2-2-1', '2-2-2', '2-2-3', '2-2-4']);

        $runId = $this->startStep($alice, '2-2-boss');
        for ($i = 1; $i <= 5; ++$i) {
            $item = $this->item($alice, $runId);
            $data = $item['data'];
            self::assertSame(['vision', 'locate', 12, [], $i, 5, 3], [$item['type'], $data['kind'], $data['visibleSeconds'], $data['announced'], $data['position'], $data['count'], $data['lives']]);
            self::assertContains($data['ask'], ['where', 'what']);
            // The last answer wrong.
            $result = $this->submitOk($alice, $runId, $item['id'], self::locateAnswer($item, $i < 5));
            self::assertSame([$i < 5, $i], [$result['success'], $result['data']['position']]);
        }

        $outcome = $this->outcome($alice, $runId);
        self::assertSame(['passed', 5, 4, 1, true], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['failures'] ?? null, $outcome['completed'] ?? null]);
        $this->runOutbox();
        self::assertSame(4 * 4 + 1, $this->exerciseXpOf($alice));
    }

    public function testAPieceEnPriseThenTheAttackersOfASquare(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->complete($alice, [...self::CHAPTER_ONE, '2-1-1', '2-1-2', '2-1-3', '2-1-4', '2-1-boss', '2-2-1', '2-2-2', '2-2-3', '2-2-4', '2-2-boss', '2-3-1', '2-3-2', '2-3-3', '2-3-4']);
        // Only the test's puzzles can be drawn (DAMA rolls this back); never twice in a run.
        $this->catalog->getConnection()->executeStatement('UPDATE puzzle SET selectable = 0');
        for ($i = 1; $i <= 5; ++$i) {
            $this->catalog->persist(new Puzzle('VI00'.$i, self::PUZZLE_FEN, self::PUZZLE_MOVES, 1500, 80, 95, 5000, ['middlegame'], 'https://lichess.org/abcdefgh', null));
        }
        $this->catalog->flush();
        $this->catalog->clear();

        $runId = $this->startStep($alice, '2-3-boss');
        $item = $this->item($alice, $runId);
        self::assertSame(['en_prise', 'en_prise', '5rk1/5pp1/8/2b5/2Bn4/2N5/5PP1/R5K1 w - - 1 2', 15, ['Nd5', 'Ne2+', 'Kh1']], [$item['data']['kind'], $item['data']['ask'], $item['data']['fen'], $item['data']['visibleSeconds'], array_column($item['data']['announced'], 'san')]);
        self::assertSame(400, $this->submit($alice, $runId, $item['id'], ['squares' => ['f2', 'd5']])->getStatusCode(), 'One square.');
        $right = $this->submitOk($alice, $runId, $item['id'], ['squares' => ['f2']]);
        self::assertSame([true, ['f2'], '5rk1/5pp1/8/2bN4/2B5/8/4nPP1/R6K b - - 4 3'], [$right['success'], $right['data']['expected'], $right['data']['finalFen']]);
        for ($i = 2; $i <= 5; ++$i) {
            $item = $this->item($alice, $runId);
            self::assertTrue($this->submitOk($alice, $runId, $item['id'], ['squares' => ['f2']])['success']);
        }
        self::assertSame(['passed', true], [$this->outcome($alice, $runId)['outcome'] ?? null, $this->outcome($alice, $runId)['completed'] ?? null]);

        // The section cleared, its hidden boss: the black pieces attacking f2.
        $runId = $this->startStep($alice, '2-3-hidden');
        $item = $this->item($alice, $runId);
        self::assertSame(['attackers', 'attackers', 'f2', 'black'], [$item['data']['kind'], $item['data']['ask'], $item['data']['target'], $item['data']['side']]);
        self::assertTrue($this->submitOk($alice, $runId, $item['id'], ['squares' => ['c5']])['success']);
        foreach ([['c5', 'd5'], [], ['e2']] as $i => $squares) {
            $item = $this->item($alice, $runId);
            $wrong = $this->submitOk($alice, $runId, $item['id'], ['squares' => $squares]);
            self::assertSame([false, ['c5'], 2 - $i], [$wrong['success'], $wrong['data']['expected'], $wrong['data']['lives']]);
        }
        $outcome = $this->outcome($alice, $runId);
        self::assertSame(['failed', 4, 1, 3, false], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['failures'] ?? null, $outcome['completed'] ?? null]);
    }

    public function testOnlyAPathStartsIt(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $response = $this->api('POST', '/api/training/runs', $alice, [
            'module' => 'vision',
            'subjectId' => $alice->getId()->toRfc4122(),
            'budgetSeconds' => 600,
            'config' => ['kind' => 'locate'],
        ]);
        self::assertSame(422, $response->getStatusCode());
    }

    /**
     * The answer to a `locate` question, right or wrong, as the client sends it.
     *
     * @param VisionItem $item
     *
     * @return array<string, mixed>
     */
    private static function locateAnswer(array $item, bool $right): array
    {
        $board = Attacks::board($item['data']['fen']);
        $target = (string) $item['data']['target'];
        if ('where' === $item['data']['ask']) {
            $holding = array_keys(array_filter($board, static fn (string $piece): bool => $piece === $target));
            $empty = 'a1';
            foreach (['a1', 'h1', 'a8', 'h8', 'd1', 'e8'] as $square) {
                if (!isset($board[$square])) {
                    $empty = $square;
                    break;
                }
            }

            return ['squares' => [$right ? $holding[0] : $empty]];
        }
        $piece = $board[$target] ?? '';
        if (!$right) {
            $piece = '' === $piece ? 'Q' : '';
        }

        return ['placement' => '' === $piece ? [] : [['square' => $target, 'piece' => $piece]]];
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
        self::assertSame('vision', $run['module'] ?? null);
        $id = $run['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return VisionItem
     */
    private function item(User $user, string $runId): array
    {
        $item = $this->json($this->api('POST', '/api/training/runs/'.$runId.'/next', $user))['item'] ?? null;
        self::assertIsArray($item, 'The run served no question.');

        /** @var VisionItem */
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
     * @return VisionResult
     */
    private function submitOk(User $user, string $runId, string $itemId, array $report): array
    {
        $response = $this->submit($user, $runId, $itemId, $report);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var VisionResult */
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

    private function exerciseXpOf(User $user): int
    {
        $sum = $this->connection()->fetchOne("SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'exercise'", [$user->getId()->toBinary()]);

        return is_numeric($sum) ? (int) $sum : 0;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
