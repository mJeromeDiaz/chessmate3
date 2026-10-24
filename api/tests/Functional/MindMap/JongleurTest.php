<?php

declare(strict_types=1);

namespace App\Tests\Functional\MindMap;

use App\Chess\Rules;
use App\Entity\User;
use App\Enum\Coordinates\Mode;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The Jongleur (docs/MIND_MAP.md, § 8) on the sample puzzles: mixed questions of 7 s, a late or
 * empty answer is a failure, the third failure ends the fight, a fight won beats the chapter 1's
 * boss (its crystals, XP and badges) and opens chapter 2; no XP of its own.
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 *
 * @phpstan-type BossItemJson array{id: string, type: string, data: array{question: string, position: int, count: int, lives: int, seconds: int, startedAt: string, square?: string, fen?: string, color?: string, move?: array{uci: string, san?: string}}}
 * @phpstan-type BossResultJson array{itemId: string, success: bool, data: array{status: string, expected: string|array{uci: string, san: string}, answer: string|null, lives: int, position: int, count: int}}
 */
final class JongleurTest extends WoodpeckerWebTestCase
{
    public function testTheQuestionsAreMixedAndJudged(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $runId = $this->startBoss($alice);

        $asked = [];
        for ($i = 1; $i <= 20; ++$i) {
            $item = $this->item($alice, $runId);
            self::assertSame(['jongleur', $i, 20, 3, 7], [$item['type'], $item['data']['position'], $item['data']['count'], $item['data']['lives'], $item['data']['seconds']]);
            if ('write' === $item['data']['question']) {
                self::assertArrayNotHasKey('san', $item['data']['move'] ?? [], 'The move to write comes without its SAN.');
            }
            $asked[] = $item['data']['question'];
            $result = $this->submitOk($alice, $runId, $item['id'], self::answer($item, true));
            self::assertSame([true, 'right', 3, $i], [$result['success'], $result['data']['status'], $result['data']['lives'], $result['data']['position']], $item['data']['question']);
        }
        $counts = array_count_values($asked);
        ksort($counts);
        self::assertSame(['color' => 4, 'find' => 4, 'name' => 4, 'play' => 4, 'write' => 4], $counts, 'Each kind as often as the others.');

        $closed = $this->getRun($alice, $runId);
        self::assertSame(['closed', 'subject_finished', 'count_done', 20, 20, true], [$closed['status'], $closed['closeReason'], $closed['summary']['context']['reason'] ?? null, $closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null, $closed['summary']['metrics']['won'] ?? null]);
        $this->runOutbox();
        self::assertSame(0, $this->xpOf($alice), 'No exercise XP of its own (the boss rewards).');
    }

    public function testTheThirdFailureEndsTheFight(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $runId = $this->startBoss($alice);

        $item = $this->item($alice, $runId);
        $wrong = $this->submitOk($alice, $runId, $item['id'], self::answer($item, false));
        self::assertSame([false, 'wrong', 2], [$wrong['success'], $wrong['data']['status'], $wrong['data']['lives']]);
        self::assertNotSame($wrong['data']['answer'], \is_array($wrong['data']['expected']) ? $wrong['data']['expected']['uci'] : $wrong['data']['expected']);

        // Nothing sent: the time ran out on the client.
        $item = $this->item($alice, $runId);
        $empty = $this->submitOk($alice, $runId, $item['id'], []);
        self::assertSame([false, 'wrong', null, 1], [$empty['success'], $empty['data']['status'], $empty['data']['answer'], $empty['data']['lives']]);

        // Right, but late.
        $item = $this->item($alice, $runId);
        $this->travel('+11 seconds');
        $late = $this->submitOk($alice, $runId, $item['id'], self::answer($item, true));
        self::assertSame([false, 'late', 0], [$late['success'], $late['data']['status'], $late['data']['lives']]);

        $closed = $this->getRun($alice, $runId);
        self::assertSame(['closed', 'lives_out', 3, 0, false], [$closed['status'], $closed['summary']['context']['reason'] ?? null, $closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null, $closed['summary']['metrics']['won'] ?? null]);
    }

    public function testAnAnswerInItsTimeCounts(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $runId = $this->startBoss($alice);
        $item = $this->item($alice, $runId);
        // 7 s, the second the start is stored to, the network's 2 s.
        $this->travel('+9 seconds');
        self::assertSame('right', $this->submitOk($alice, $runId, $item['id'], self::answer($item, true))['data']['status']);
        $item = $this->item($alice, $runId);
        self::assertSame($item['id'], $this->item($alice, $runId)['id'], 'A reload gets the same question back, its time running.');
        self::assertSame(400, $this->submit($alice, $runId, $item['id'], ['moves' => ['e2e4', 'e7e5']])->getStatusCode(), 'One answer.');
    }

    public function testAFightWonBeatsTheBossAndOpensChapterTwo(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        foreach (['1-1', '1-2', '1-3', '1-4', '1-chest', '1-5', '1-6', '1-7', '1-8', '1-9'] as $step) {
            $this->completed($alice, $step);
        }

        $response = $this->api('POST', '/api/mind-map/visualisation/steps/1-boss/start', $alice);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);
        self::assertSame('jongleur', $run['module'] ?? null);
        $runId = $run['id'] ?? null;
        self::assertIsString($runId);

        // Two failures: still alive.
        for ($i = 0; $i < 20; ++$i) {
            $item = $this->item($alice, $runId);
            $this->submitOk($alice, $runId, $item['id'], self::answer($item, $i >= 2));
        }

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['passed', 20, 18, 3, true], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['lives'] ?? null, $outcome['completed'] ?? null]);
        self::assertSame(200, \is_array($outcome['chest'] ?? null) ? $outcome['chest']['crystals'] ?? null : null);

        $path = $this->json($this->api('GET', '/api/mind-map/visualisation', $alice));
        /** @var list<array{number: int, status: string, boss: array{status: string}}> $chapters */
        $chapters = $path['chapters'] ?? [];
        self::assertSame(['done', 'open'], [$chapters[0]['boss']['status'], $chapters[1]['boss']['status']], 'The Cyclope is open with its chapter.');
        self::assertNotSame('locked', $chapters[1]['status'], 'Chapter 2 opens.');
        /** @var list<array{badge: string}> $badges */
        $badges = $path['badges'] ?? [];
        self::assertSame(['boss_jongleur', 'chapter_1'], array_values(array_intersect(['boss_jongleur', 'chapter_1'], array_column($badges, 'badge'))));
    }

    public function testALostFightCountsNothing(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        foreach (['1-1', '1-2', '1-3', '1-4', '1-chest', '1-5', '1-6', '1-7', '1-8', '1-9'] as $step) {
            $this->completed($alice, $step);
        }
        $run = $this->json($this->api('POST', '/api/mind-map/visualisation/steps/1-boss/start', $alice))['run'] ?? null;
        self::assertIsArray($run);
        $runId = $run['id'] ?? null;
        self::assertIsString($runId);
        for ($i = 0; $i < 3; ++$i) {
            $item = $this->item($alice, $runId);
            $this->submitOk($alice, $runId, $item['id'], self::answer($item, false));
        }

        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$runId, $alice));
        self::assertSame(['failed', false], [$outcome['outcome'] ?? null, $outcome['completed'] ?? null]);
    }

    public function testOnlyThePathStartsAFight(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        foreach ([['count' => 20], ['count' => 20, 'pathStep' => 'visualisation:1-boss'], []] as $config) {
            $response = $this->api('POST', '/api/training/runs', $alice, [
                'module' => 'jongleur',
                'subjectId' => $alice->getId()->toRfc4122(),
                'budgetSeconds' => 1_800,
                'config' => $config,
            ]);
            self::assertSame(422, $response->getStatusCode(), json_encode($config, \JSON_THROW_ON_ERROR));
        }
    }

    /**
     * The answer to a question, right or wrong, as the client sends it.
     *
     * @param BossItemJson $item
     *
     * @return array<string, mixed>
     */
    private static function answer(array $item, bool $right): array
    {
        $data = $item['data'];
        $square = $data['square'] ?? '';
        $other = 'a1' === $square ? 'h8' : 'a1';

        return match ($data['question']) {
            'find', 'name' => ['answers' => [['index' => 0, 'square' => $right ? $square : $other, 'ms' => 1_000]]],
            'color' => ['answers' => [['index' => 0, 'square' => $right === (Mode::LIGHT === Mode::colorOf($square)) ? Mode::LIGHT : Mode::DARK, 'ms' => 1_000]]],
            'play' => ['moves' => [$right ? ($data['move']['uci'] ?? '') : self::otherMove($data['fen'] ?? '', $data['move']['uci'] ?? '')]],
            'write' => ['written' => [self::san($data['fen'] ?? '', $right ? ($data['move']['uci'] ?? '') : self::otherMove($data['fen'] ?? '', $data['move']['uci'] ?? ''))]],
            default => self::fail('Unknown question '.$data['question']),
        };
    }

    /** Chapter 1's exercises done, its boss's fight started (20 questions). */
    private function startBoss(User $user): string
    {
        foreach (['1-1', '1-2', '1-3', '1-4', '1-chest', '1-5', '1-6', '1-7', '1-8', '1-9'] as $step) {
            $this->completed($user, $step);
        }
        $response = $this->api('POST', '/api/mind-map/visualisation/steps/1-boss/start', $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);
        $id = $run['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return BossItemJson
     */
    private function item(User $user, string $runId): array
    {
        $item = $this->json($this->api('POST', '/api/training/runs/'.$runId.'/next', $user))['item'] ?? null;
        self::assertIsArray($item, 'The run served no question.');

        /** @var BossItemJson */
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
     * @return BossResultJson
     */
    private function submitOk(User $user, string $runId, string $itemId, array $report): array
    {
        $response = $this->submit($user, $runId, $itemId, $report);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var BossResultJson */
        return $result;
    }

    /**
     * @return RunJson
     */
    private function getRun(User $user, string $runId): array
    {
        /** @var RunJson */
        return $this->json($this->api('GET', '/api/training/runs/'.$runId, $user));
    }

    /** A step set as done. */
    private function completed(User $user, string $step): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, 'visualisation', ?, 2, '2026-09-28 09:00:00', '2026-09-28 09:00:00')",
            [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $step],
        );
    }

    private static function san(string $fen, string $uci): string
    {
        return Rules::fromFen($fen)->playUci($uci)->san ?? $uci;
    }

    /** A legal move of the position other than $uci. */
    private static function otherMove(string $fen, string $uci): string
    {
        foreach (Rules::fromFen($fen)->legalMoves() as $move) {
            if (Rules::uci($move) !== $uci) {
                return Rules::uci($move);
            }
        }
        self::fail('A single legal move.');
    }

    private function xpOf(User $user): int
    {
        $sum = $this->connection()->fetchOne("SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'exercise'", [$user->getId()->toBinary()]);

        return is_numeric($sum) ? (int) $sum : 0;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
