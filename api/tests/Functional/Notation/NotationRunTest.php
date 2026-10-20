<?php

declare(strict_types=1);

namespace App\Tests\Functional\Notation;

use App\Chess\Rules;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The notation module in timed runs (docs/NOTATION.md) on the sample puzzles: a move announced to
 * play, a sequence to play for both sides, a line to write; the server's verdicts, a counted run
 * that ends at its count, the XP, the options checked.
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 *
 * @phpstan-type SanMove array{uci: string, san: string}
 * @phpstan-type NotationItem array{id: string, type: string, data: array{kind: string, fen: string, color: string, position: int, count: int|null, announced?: SanMove, moves?: list<SanMove>, plies?: int}}
 * @phpstan-type NotationResult array{itemId: string, success: bool, data: array{status: string, expected: list<SanMove>, answer: list<mixed>, mistakeAt: int|null, reason: string|null, position: int, count: int|null}}
 */
final class NotationRunTest extends WoodpeckerWebTestCase
{
    public function testAMoveAnnouncedIsPlayed(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->start($alice, ['kind' => 'play']);
        $item = $this->item($alice, $run['id']);
        self::assertSame(['notation', 'play', 1, null], [$item['type'], $item['data']['kind'], $item['data']['position'], $item['data']['count']]);
        $announced = $item['data']['announced'] ?? null;
        self::assertIsArray($announced);
        self::assertNotNull(Rules::fromFen($item['data']['fen'])->playUci($announced['uci']), 'A legal move of the position.');

        $this->travel('+5 seconds');
        $right = $this->submitOk($alice, $run['id'], $item['id'], ['moves' => [$announced['uci']]]);
        self::assertSame([true, 'right', null, [$announced]], [$right['success'], $right['data']['status'], $right['data']['mistakeAt'], $right['data']['expected']]);

        $next = $this->item($alice, $run['id']);
        self::assertSame(2, $next['data']['position']);
        $expected = $next['data']['announced']['uci'] ?? '';
        $wrong = $this->submitOk($alice, $run['id'], $next['id'], ['moves' => [self::otherMove($next['data']['fen'], $expected)]]);
        self::assertSame([false, 'wrong', 0], [$wrong['success'], $wrong['data']['status'], $wrong['data']['mistakeAt']]);
        self::assertSame(400, $this->submit($alice, $run['id'], $this->item($alice, $run['id'])['id'], ['moves' => ['e2e4', 'e7e5']])->getStatusCode(), 'One move.');

        $this->travel('+10 minutes');
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame([2, 1, 'play'], [$closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null, $closed['summary']['metrics']['kind'] ?? null]);
        $this->runOutbox();
        self::assertSame(4 + 1, $this->xpOf($alice));
    }

    public function testASequenceIsPlayedForBothSides(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->start($alice, ['kind' => 'sequence']);
        $item = $this->item($alice, $run['id']);
        $moves = $item['data']['moves'] ?? [];
        self::assertGreaterThanOrEqual(4, \count($moves));
        self::assertLessThanOrEqual(8, \count($moves));

        $right = $this->submitOk($alice, $run['id'], $item['id'], ['moves' => array_column($moves, 'uci')]);
        self::assertTrue($right['success']);

        // Stopped at the first wrong move: the moves played so far. The wrong one where the position
        // has another move (a forced reply has none).
        $next = $this->item($alice, $run['id']);
        $nextMoves = array_column($next['data']['moves'] ?? [], 'uci');
        $at = 0;
        while (\count(Rules::fromFen(self::fenAfter($next['data']['fen'], \array_slice($nextMoves, 0, $at)))->legalMoves()) < 2) {
            ++$at;
        }
        $played = [...\array_slice($nextMoves, 0, $at), self::otherMove(self::fenAfter($next['data']['fen'], \array_slice($nextMoves, 0, $at)), $nextMoves[$at])];
        $wrong = $this->submitOk($alice, $run['id'], $next['id'], ['moves' => $played]);
        self::assertSame([false, $at], [$wrong['success'], $wrong['data']['mistakeAt']]);
        self::assertSame($played, array_column($wrong['data']['answer'], 'uci'));
    }

    public function testALineIsWrittenAndAMistakeShown(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->start($alice, ['kind' => 'write']);
        $item = $this->item($alice, $run['id']);
        self::assertArrayNotHasKey('moves', $item['data'], 'Nothing of the solution is sent.');
        $expected = $this->expectedOf($item['id']);
        self::assertSame(\count($expected), $item['data']['plies'] ?? null);

        // Written with move numbers, without check marks.
        $sans = self::sansOf($item['data']['fen'], $expected);
        $written = implode(' ', array_map(static fn (string $san, int $i): string => (0 === $i % 2 ? (intdiv($i, 2) + 1).'. ' : '').rtrim($san, '+#'), $sans, array_keys($sans)));
        $right = $this->submitOk($alice, $run['id'], $item['id'], ['written' => [$written]]);
        self::assertSame([true, $sans], [$right['success'], $right['data']['answer']]);

        $next = $this->item($alice, $run['id']);
        $wrong = $this->submitOk($alice, $run['id'], $next['id'], ['written' => ['Ke9']]);
        self::assertSame([false, 0, 'illegal', []], [$wrong['success'], $wrong['data']['mistakeAt'], $wrong['data']['reason'], $wrong['data']['answer']]);
        self::assertSame(self::sansOf($next['data']['fen'], $this->expectedOf($next['id'])), array_column($wrong['data']['expected'], 'san'), 'The right line, to compare.');
    }

    public function testACountedRunEndsAtItsCount(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->start($alice, ['kind' => 'play', 'count' => 2], 1_800);
        $first = $this->item($alice, $run['id']);
        self::assertSame([1, 2], [$first['data']['position'], $first['data']['count']]);
        $this->submitOk($alice, $run['id'], $first['id'], ['moves' => [$first['data']['announced']['uci'] ?? '']]);
        $second = $this->item($alice, $run['id']);
        $last = $this->submitOk($alice, $run['id'], $second['id'], ['moves' => [$second['data']['announced']['uci'] ?? '']]);
        self::assertSame([2, 2], [$last['data']['position'], $last['data']['count']]);

        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['subject_finished', 2, 2], [$closed['closeReason'], $closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null]);
    }

    public function testTheOptionsAreChecked(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        foreach ([['kind' => 'guess'], ['kind' => 'play', 'count' => 0], ['kind' => 'play', 'count' => 31], ['kind' => 'play', 'level' => 2], []] as $config) {
            self::assertSame(422, $this->startResponse($alice, $config, 300)->getStatusCode(), json_encode($config, \JSON_THROW_ON_ERROR));
        }
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return RunJson
     */
    private function start(User $user, array $config, int $seconds = 300): array
    {
        $response = $this->startResponse($user, $config, $seconds);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function startResponse(User $user, array $config, int $seconds): Response
    {
        return $this->api('POST', '/api/training/runs', $user, [
            'module' => 'notation',
            'subjectId' => $user->getId()->toRfc4122(),
            'budgetSeconds' => $seconds,
            'config' => $config,
        ]);
    }

    /**
     * @return NotationItem
     */
    private function item(User $user, string $runId): array
    {
        $item = $this->json($this->api('POST', '/api/training/runs/'.$runId.'/next', $user))['item'] ?? null;
        self::assertIsArray($item, 'The run served no item.');

        /** @var NotationItem */
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
     * @return NotationResult
     */
    private function submitOk(User $user, string $runId, string $itemId, array $report): array
    {
        $response = $this->submit($user, $runId, $itemId, $report);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var NotationResult */
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

    /**
     * The moves expected of an item, as stored.
     *
     * @return list<string>
     */
    private function expectedOf(string $itemId): array
    {
        $json = $this->connection()->fetchOne('SELECT expected FROM notation_attempt WHERE id = ?', [Uuid::fromString($itemId)->toBinary()]);
        self::assertIsString($json);
        /** @var list<string> $expected */
        $expected = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);

        return $expected;
    }

    /**
     * @param list<string> $ucis
     *
     * @return list<string>
     */
    private static function sansOf(string $fen, array $ucis): array
    {
        $rules = Rules::fromFen($fen);

        return array_map(static fn (string $uci): string => $rules->playUci($uci)->san ?? $uci, $ucis);
    }

    /**
     * @param list<string> $ucis
     */
    private static function fenAfter(string $fen, array $ucis): string
    {
        $rules = Rules::fromFen($fen);
        foreach ($ucis as $uci) {
            $rules->playUci($uci);
        }

        return $rules->fen();
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
        $sum = $this->connection()->fetchOne('SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ?', [$user->getId()->toBinary()]);

        return is_numeric($sum) ? (int) $sum : 0;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
