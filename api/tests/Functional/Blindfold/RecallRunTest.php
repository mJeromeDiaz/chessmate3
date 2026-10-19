<?php

declare(strict_types=1);

namespace App\Tests\Functional\Blindfold;

use App\Blindfold\Recall\Placement;
use App\Blindfold\Recall\RecallRules;
use App\Entity\Catalog\Puzzle;
use App\Entity\User;
use App\Gamification\Xp\XpRules;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Position recall (docs/BLINDFOLD_RECALL.md): a position shown, hidden, rebuilt and corrected
 * square by square; three exact rebuilds open the next level; generated positions first, then
 * puzzle positions (the moves announced at the last levels); never rated; one exercise per
 * rebuild; a session step at the player's level.
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 *
 * @phpstan-type RecallItem array{id: string, type: string, data: array{fen: string, level: int, stage: int, mode: string, visibleSeconds: int, hiddenSeconds: int, announced: list<array{uci: string, san: string}>, startedAt: string}}
 * @phpstan-type ResultJson array{itemId: string, success: bool, data: array{status: string, expected: int, correct: int, wrong: int, extra: int, missing: int, accuracy: float|int, expectedPlacement: string, unlockedLevel: int|null, durationMs: int|null}}
 * @phpstan-type LevelJson array{level: int, stage: int, played: int, exact: int, close: int, missed: int, unlocked: bool}
 */
final class RecallRunTest extends WoodpeckerWebTestCase
{
    /**
     * 14 pieces once Black's first move is played (no capture), and three more moves that replay:
     * Nd5, Ne2+, Kh1.
     */
    private const FOURTEEN = '5rk1/5ppp/2n5/2b5/2B5/2N5/5PPP/5RK1 b - - 0 1';
    private const FOURTEEN_MOVES = 'c6d4 c3d5 d4e2 g1h1';

    public function testThreeExactRebuildsOpenTheNextLevel(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, ['level' => 1, 'mode' => 'free'], RecallRules::FREE_BUDGET_SECONDS);

        // Level 1: both kings and a white pawn, White to move, nothing announced.
        $item = $this->item($alice, $run['id']);
        self::assertSame('blindfold_recall', $item['type']);
        self::assertSame([1, 1, 'free', 8, 3, []], [
            $item['data']['level'], $item['data']['stage'], $item['data']['mode'],
            $item['data']['visibleSeconds'], $item['data']['hiddenSeconds'], $item['data']['announced'],
        ]);
        $squares = Placement::squares($item['data']['fen']);
        $pieces = array_values($squares);
        sort($pieces);
        self::assertSame(['K', 'P', 'k'], $pieces);
        self::assertStringEndsWith(' w - - 0 1', $item['data']['fen']);
        self::assertSame($item['id'], $this->item($alice, $run['id'])['id'], 'a reload gets the same position');

        $this->travel('+12 seconds');
        $result = $this->submitOk($alice, $run['id'], $item['id'], self::placementOf($item['data']['fen']));
        self::assertTrue($result['success']);
        self::assertSame(['exact', 3, 3, 0, 0, 0, null, 12_000], [
            $result['data']['status'], $result['data']['expected'], $result['data']['correct'], $result['data']['wrong'],
            $result['data']['extra'], $result['data']['missing'], $result['data']['unlockedLevel'], $result['data']['durationMs'],
        ]);
        self::assertEquals(1, $result['data']['accuracy']);
        self::assertSame(explode(' ', $item['data']['fen'])[0], $result['data']['expectedPlacement']);
        self::assertSame(409, $this->submit($alice, $run['id'], $item['id'], self::placementOf($item['data']['fen']))->getStatusCode(), 'one try');

        // The pawn one square off, and an extra queen: 2 right out of 3, 2 extra.
        $item = $this->item($alice, $run['id']);
        $placed = self::placementOf($item['data']['fen']);
        foreach ($placed as $i => $piece) {
            if ('P' === $piece['piece']) {
                $placed[$i]['square'] = self::freeSquareNear($item['data']['fen'], $piece['square']);
            }
        }
        $placed[] = ['square' => self::freeSquare($item['data']['fen'], $placed), 'piece' => 'Q'];
        $this->travel('+5 seconds');
        $result = $this->submitOk($alice, $run['id'], $item['id'], $placed);
        self::assertFalse($result['success']);
        self::assertSame(['missed', 2, 2, 2, 1], [$result['data']['status'], $result['data']['correct'], $result['data']['wrong'], $result['data']['extra'], $result['data']['missing']]);
        self::assertEquals(0.4, $result['data']['accuracy'], '2 / (3 + 2)');

        // Two more exact ones: the third opens level 2, once.
        $this->rebuild($alice, $run['id']);
        $third = $this->rebuild($alice, $run['id']);
        self::assertSame(2, $third['data']['unlockedLevel']);
        self::assertNull($this->rebuild($alice, $run['id'])['data']['unlockedLevel'], 'a fourth one opens nothing');

        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        $closed = $this->getRun($alice, $run['id']);
        $summary = $closed['summary'];
        self::assertNotNull($summary);
        self::assertSame([5, 4], [$summary['itemCount'], $summary['successCount']]);
        $metrics = $summary['metrics'];
        self::assertSame([1, 1, 'free', 4, 0, 1, 2], [
            $metrics['level'] ?? null, $metrics['stage'] ?? null, $metrics['mode'] ?? null, $metrics['exact'] ?? null,
            $metrics['close'] ?? null, $metrics['missed'] ?? null, $metrics['unlockedLevel'] ?? null,
        ]);
        self::assertEquals(0.88, $metrics['accuracy'] ?? null, '(4 × 1 + 0.4) / 5');

        $review = $this->review($alice, $run['id']);
        self::assertSame(['ok', 'fail', 'ok', 'ok', 'ok'], array_column($review['items'], 'status'));
        self::assertSame(['blindfold_recall'], array_values(array_unique(array_column($review['items'], 'type'))));

        // Where the user stands.
        $overview = $this->overview($alice);
        self::assertSame(2, $overview['highest']);
        self::assertCount(RecallRules::MAX_LEVEL, $overview['levels']);
        self::assertSame([1, 5, 4, 0, 1, true], array_values(array_diff_key($overview['levels'][0], ['stage' => true])));
        self::assertSame([2, true, 0], [$overview['levels'][1]['level'], $overview['levels'][1]['unlocked'], $overview['levels'][1]['played']]);
        self::assertFalse($overview['levels'][2]['unlocked']);
        self::assertCount(RecallRules::MAX_LEVEL, $overview['rules']['levels']);

        // Never rated; one exercise per rebuild, its XP by the verdict, the unlock bonus once.
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM puzzle_rating WHERE user_id = ?', $alice));
        $this->runOutbox();
        self::assertSame(5, $this->countRows("SELECT COUNT(*) FROM activity_log_entry WHERE user_id = ? AND exercise_type = 'blindfold_recall'", $alice));
        self::assertSame(4 * XpRules::RECALL_EXACT[1] + XpRules::RECALL_UNLOCK, $this->xpOf($alice));

        // Level 2 is open now.
        $next = $this->startOk($alice, ['level' => 2, 'mode' => 'timed'], 600);
        self::assertCount(4, Placement::squares($this->item($alice, $next['id'])['data']['fen']));
    }

    public function testLockedLevelsAndInvalidOptionsAreRefused(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        self::assertSame(409, $this->start($alice, ['level' => 2, 'mode' => 'free'], RecallRules::FREE_BUDGET_SECONDS)->getStatusCode(), 'locked');
        self::assertSame(422, $this->start($alice, ['level' => 21, 'mode' => 'free'], RecallRules::FREE_BUDGET_SECONDS)->getStatusCode());
        self::assertSame(422, $this->start($alice, ['level' => '1', 'mode' => 'free'], RecallRules::FREE_BUDGET_SECONDS)->getStatusCode());
        self::assertSame(422, $this->start($alice, ['level' => 1], 600)->getStatusCode(), 'mode required');
        self::assertSame(422, $this->start($alice, ['level' => 1, 'mode' => 'free'], 600)->getStatusCode(), 'a free run lasts an hour');
        self::assertSame(422, $this->start($alice, ['level' => 1, 'mode' => 'timed', 'visibleSeconds' => 5], 600)->getStatusCode());

        $run = $this->startOk($alice, ['level' => 1, 'mode' => 'timed'], 600);
        $item = $this->item($alice, $run['id']);
        $twice = [['square' => 'a1', 'piece' => 'K'], ['square' => 'a1', 'piece' => 'k']];
        self::assertSame(400, $this->submit($alice, $run['id'], $item['id'], $twice)->getStatusCode(), 'two pieces on a square');
        self::assertSame(422, $this->submit($alice, $run['id'], $item['id'], [['square' => 'a9', 'piece' => 'K']])->getStatusCode());
        self::assertSame(422, $this->submit($alice, $run['id'], $item['id'], [['square' => 'a1', 'piece' => 'X']])->getStatusCode());

        // Nothing placed: every piece missing.
        $result = $this->submitOk($alice, $run['id'], $item['id'], []);
        self::assertSame(['missed', 0, 3], [$result['data']['status'], $result['data']['correct'], $result['data']['missing']]);

        // Another user's subject.
        $bob = $this->createUserIn('bob@example.com');
        $response = $this->api('POST', '/api/training/runs', $bob, ['module' => 'blindfold_recall', 'subjectId' => $alice->getId()->toRfc4122(), 'budgetSeconds' => 600, 'config' => ['level' => 1, 'mode' => 'timed']]);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testPuzzleLevelsShowARealPositionThenAnnounceMoves(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        // Only the test's puzzle can be drawn (DAMA rolls this back).
        $this->catalog->getConnection()->executeStatement('UPDATE puzzle SET selectable = 0');
        $this->addPuzzle('RC001', self::FOURTEEN, self::FOURTEEN_MOVES);
        $this->openUpTo($alice, 20);

        // Level 13: the puzzle's position after Black's first move.
        $run = $this->startOk($alice, ['level' => 13, 'mode' => 'free'], RecallRules::FREE_BUDGET_SECONDS);
        $item = $this->item($alice, $run['id']);
        self::assertSame([13, 4, 15, []], [$item['data']['level'], $item['data']['stage'], $item['data']['visibleSeconds'], $item['data']['announced']]);
        self::assertSame('5rk1/5ppp/8/2b5/2Bn4/2N5/5PPP/5RK1 w - - 1 2', $item['data']['fen']);
        $result = $this->submitOk($alice, $run['id'], $item['id'], self::placementOf($item['data']['fen']));
        self::assertSame(['exact', 14], [$result['data']['status'], $result['data']['expected']]);
        // The only puzzle of the level was served: the run cannot go on.
        $this->api('POST', '/api/training/runs/'.$run['id'].'/next', $alice);
        self::assertSame('closed', $this->getRun($alice, $run['id'])['status']);

        // Level 20: three moves announced; the position to rebuild is the one after them.
        $run = $this->startOk($alice, ['level' => 20, 'mode' => 'free'], RecallRules::FREE_BUDGET_SECONDS);
        $item = $this->item($alice, $run['id']);
        self::assertSame([20, 5, 15], [$item['data']['level'], $item['data']['stage'], $item['data']['visibleSeconds']]);
        self::assertSame([['uci' => 'c3d5', 'san' => 'Nd5'], ['uci' => 'd4e2', 'san' => 'Ne2+'], ['uci' => 'g1h1', 'san' => 'Kh1']], $item['data']['announced']);
        $shown = $this->submitOk($alice, $run['id'], $item['id'], self::placementOf($item['data']['fen']));
        self::assertSame(['missed', 11, 3], [$shown['data']['status'], $shown['data']['correct'], $shown['data']['missing']], 'the position shown is not the one to rebuild');
        self::assertSame('5rk1/5ppp/8/2bN4/2B5/8/4nPPP/5R1K', $shown['data']['expectedPlacement']);
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        $review = $this->review($alice, $run['id']);
        self::assertSame(['Nd5', 'Ne2+', 'Kh1'], array_column($review['items'][0]['data']['announced'] ?? [], 'san'));

        // No puzzle of 16 pieces: level 14 cannot start.
        $refused = $this->start($alice, ['level' => 14, 'mode' => 'free'], RecallRules::FREE_BUDGET_SECONDS);
        self::assertSame(409, $refused->getStatusCode());
        self::assertStringContainsString('No puzzle fits this level.', (string) $refused->getContent());
    }

    public function testASessionStepPlaysTheHighestLevelOpen(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->openUpTo($alice, 4);

        $refused = $this->api('POST', '/api/training/sessions', $alice, ['steps' => [['module' => 'blindfold_recall', 'minutes' => 10, 'settings' => ['level' => 5]]]]);
        self::assertSame(422, $refused->getStatusCode(), 'level 5 is not open yet');

        $session = $this->api('POST', '/api/training/sessions', $alice, ['steps' => [['module' => 'blindfold_recall', 'minutes' => 10, 'settings' => ['level' => 'mine']]]]);
        self::assertSame(201, $session->getStatusCode(), (string) $session->getContent());
        $id = $this->json($session)['id'] ?? null;
        self::assertIsString($id);
        $launched = $this->api('POST', '/api/training/sessions/'.$id.'/next', $alice);
        self::assertSame(200, $launched->getStatusCode(), (string) $launched->getContent());
        $run = $this->json($launched)['run'] ?? null;
        self::assertIsArray($run);
        self::assertSame([600, 'blindfold_recall'], [$run['budgetSeconds'] ?? null, $run['module'] ?? null]);
        $item = $this->item($alice, \is_string($run['id'] ?? null) ? $run['id'] : '');
        self::assertSame([4, 'timed'], [$item['data']['level'], $item['data']['mode']]);
        // Level 4: the kings, a white bishop and three pawns.
        $pieces = array_map('strtolower', array_values(Placement::squares($item['data']['fen'])));
        sort($pieces);
        self::assertSame(['b', 'k', 'k', 'p', 'p', 'p'], $pieces);
        self::assertContains('B', Placement::squares($item['data']['fen']));
    }

    /**
     * Opens every level up to $level: three exact rebuilds written at each one before it, in a run
     * of the user's.
     */
    private function openUpTo(User $user, int $level): void
    {
        $run = $this->startOk($user, ['level' => 1, 'mode' => 'timed'], 600);
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $user);
        for ($below = 1; $below < $level; ++$below) {
            for ($i = 0; $i < RecallRules::UNLOCK_EXACT; ++$i) {
                $this->connection()->executeStatement(
                    "INSERT INTO blindfold_recall_attempt (id, level, visible_seconds, fen, expected_placement, announced, puzzle_id, piece_count, status, placement, correct, wrong, extra, missing, unlocked, started_at, submitted_at, duration_ms, user_id, run_id)
                     VALUES (?, ?, 8, '4k3/8/8/8/8/8/4P3/4K3 w - - 0 1', '4k3/8/8/8/8/8/4P3/4K3', '[]', NULL, 3, 'exact', '{}', 3, 0, 0, 0, 0, '2026-07-01 10:00:00', '2026-07-01 10:00:10', 10000, ?, ?)",
                    [Uuid::v7()->toBinary(), $below, $user->getId()->toBinary(), Uuid::fromString($run['id'])->toBinary()],
                    [ParameterType::BINARY, ParameterType::INTEGER, ParameterType::BINARY, ParameterType::BINARY],
                );
            }
        }
    }

    private function addPuzzle(string $lichessId, string $fen, string $moves): void
    {
        $this->catalog->persist(new Puzzle($lichessId, $fen, $moves, 1500, 80, 95, 5000, ['middlegame'], 'https://lichess.org/abcdefgh', null));
        $this->catalog->flush();
        $this->catalog->clear();
    }

    /**
     * An exact rebuild of the next position.
     *
     * @return ResultJson
     */
    private function rebuild(User $user, string $runId): array
    {
        $item = $this->item($user, $runId);
        $this->travel('+5 seconds');

        return $this->submitOk($user, $runId, $item['id'], self::placementOf($item['data']['fen']));
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

    /** An empty square next to $square, on ranks 2 to 7 (a pawn may stand there). */
    private static function freeSquareNear(string $fen, string $square): string
    {
        $taken = Placement::squares($fen);
        foreach ([[1, 0], [-1, 0], [0, 1], [0, -1], [1, 1], [-1, -1], [1, -1], [-1, 1]] as [$df, $dr]) {
            $file = \chr(\ord($square[0]) + $df);
            $rank = (int) $square[1] + $dr;
            if ($file >= 'a' && $file <= 'h' && $rank >= 2 && $rank <= 7 && !isset($taken[$file.$rank])) {
                return $file.$rank;
            }
        }

        self::fail('No free square near '.$square);
    }

    /**
     * @param list<array{square: string, piece: string}> $placed
     */
    private static function freeSquare(string $fen, array $placed): string
    {
        $taken = Placement::squares($fen) + array_column($placed, 'piece', 'square');
        foreach (range('a', 'h') as $file) {
            foreach (range(1, 8) as $rank) {
                if (!isset($taken[$file.$rank])) {
                    return $file.$rank;
                }
            }
        }

        self::fail('No free square.');
    }

    /**
     * @param array<string, mixed> $config
     */
    private function start(User $user, array $config, int $budget): Response
    {
        return $this->api('POST', '/api/training/runs', $user, ['module' => 'blindfold_recall', 'subjectId' => $user->getId()->toRfc4122(), 'budgetSeconds' => $budget, 'config' => $config]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return RunJson
     */
    private function startOk(User $user, array $config, int $budget): array
    {
        $response = $this->start($user, $config, $budget);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    /**
     * @return RecallItem
     */
    private function item(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $item = $this->json($response)['item'] ?? null;
        self::assertIsArray($item, 'The run served no item.');

        /** @var RecallItem */
        return $item;
    }

    /**
     * @param list<array{square: string, piece: string}> $placement
     */
    private function submit(User $user, string $runId, string $itemId, array $placement): Response
    {
        return $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId, 'placement' => $placement]);
    }

    /**
     * @param list<array{square: string, piece: string}> $placement
     *
     * @return ResultJson
     */
    private function submitOk(User $user, string $runId, string $itemId, array $placement): array
    {
        $response = $this->submit($user, $runId, $itemId, $placement);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var ResultJson */
        return $result;
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
     * @return array{xp: int, items: list<array{type: string, status: string, durationMs: int|null, data: array{announced?: list<array{uci: string, san: string}>}}>}
     */
    private function review(User $user, string $runId): array
    {
        $response = $this->api('GET', '/api/training/runs/'.$runId.'/review', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{xp: int, items: list<array{type: string, status: string, durationMs: int|null, data: array{announced?: list<array{uci: string, san: string}>}}>} */
        return $this->json($response);
    }

    /**
     * @return array{rules: array{levels: list<array<string, mixed>>}, levels: list<LevelJson>, highest: int}
     */
    private function overview(User $user): array
    {
        $response = $this->api('GET', '/api/blindfold/recall', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{rules: array{levels: list<array<string, mixed>>}, levels: list<LevelJson>, highest: int} */
        return $this->json($response);
    }

    private function countRows(string $sql, User $user): int
    {
        $count = $this->connection()->fetchOne($sql, [$user->getId()->toBinary()]);
        self::assertTrue(is_numeric($count));

        return (int) $count;
    }

    private function xpOf(User $user): int
    {
        return $this->countRows('SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ?', $user);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
