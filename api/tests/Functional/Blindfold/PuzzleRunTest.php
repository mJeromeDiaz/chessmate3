<?php

declare(strict_types=1);

namespace App\Tests\Functional\Blindfold;

use App\Blindfold\Puzzle\PuzzleRules;
use App\Entity\Catalog\Puzzle;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use PChess\Chess\Chess;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blindfold puzzles in timed runs (docs/BLINDFOLD.md): open to everyone (the coordinates are not
 * a prerequisite), fixed levels and lengths, solved / helped / failed from the moves replayed by the server, never
 * rated, XP and statistics.
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 *
 * @phpstan-type PuzzleJson array{id: string, fen: string, moves: list<string>, playerColor: string, rating: int, themes: list<string>, gameUrl: string}
 * @phpstan-type BlindfoldItem array{id: string, type: string, data: array{puzzle: PuzzleJson, level: string, length: int, visibleSeconds: int, hiddenSeconds: int, peeks: int, startedAt: string}}
 */
final class PuzzleRunTest extends WoodpeckerWebTestCase
{
    private const EASY_SHORT = ['level' => 'easy', 'length' => 2, 'visibleSeconds' => 10];

    public function testOpenWithoutAnyCoordinatesSeries(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        self::assertSame(201, $this->start($alice, self::EASY_SHORT)->getStatusCode());
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM coordinates_series WHERE user_id = ?', $alice));
    }

    public function testShownThenSolvedFromMemoryNeverRated(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, self::EASY_SHORT);

        $first = $this->item($alice, $run['id']);
        self::assertSame(['blindfold_puzzle', 'easy', 2, 10, PuzzleRules::HIDDEN_SECONDS, PuzzleRules::PEEKS], [
            $first['type'], $first['data']['level'], $first['data']['length'], $first['data']['visibleSeconds'], $first['data']['hiddenSeconds'], $first['data']['peeks'],
        ]);
        self::assertContains('short', $first['data']['puzzle']['themes']);
        self::assertGreaterThanOrEqual(600, $first['data']['puzzle']['rating']);
        self::assertLessThanOrEqual(1000, $first['data']['puzzle']['rating']);
        self::assertSame($first['id'], $this->item($alice, $run['id'])['id'], 'A reload gets the same puzzle.');

        // Solved; solved after a mistake (and the peek); failed at the second mistake.
        $this->travel('+20 seconds');
        $solved = $this->submitOk($alice, $run['id'], $first['id'], self::solution($first['data']));
        self::assertSame([true, 'solved', 0, 20_000], [$solved['success'], $solved['data']['status'] ?? null, $solved['data']['mistakes'] ?? null, $solved['data']['durationMs'] ?? null]);
        self::assertSame(409, $this->submit($alice, $run['id'], $first['id'], self::solution($first['data']))->getStatusCode(), 'Already submitted.');

        $second = $this->item($alice, $run['id']);
        self::assertNotSame($first['data']['puzzle']['id'], $second['data']['puzzle']['id']);
        $helped = $this->submitOk($alice, $run['id'], $second['id'], [self::wrongFirstMove($second), ...self::solution($second['data'])]);
        self::assertSame([false, 'helped', 1], [$helped['success'], $helped['data']['status'] ?? null, $helped['data']['mistakes'] ?? null]);

        $third = $this->item($alice, $run['id']);
        $wrong = self::wrongFirstMove($third);
        $failed = $this->submitOk($alice, $run['id'], $third['id'], [$wrong, $wrong]);
        self::assertSame([false, 'failed', 2], [$failed['success'], $failed['data']['status'] ?? null, $failed['data']['mistakes'] ?? null]);

        // The puzzle on screen at the end is not counted.
        $this->item($alice, $run['id']);
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['stopped', 3, 1], [$closed['closeReason'], $closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null]);
        self::assertSame(['easy', 2, 10, 1, 1, 1], [
            $closed['summary']['metrics']['level'] ?? null,
            $closed['summary']['metrics']['length'] ?? null,
            $closed['summary']['metrics']['visibleSeconds'] ?? null,
            $closed['summary']['metrics']['solved'] ?? null,
            $closed['summary']['metrics']['helped'] ?? null,
            $closed['summary']['metrics']['failed'] ?? null,
        ]);

        $review = $this->review($alice, $run['id']);
        self::assertSame(['ok', 'hint', 'fail'], array_column($review['items'], 'status'));
        self::assertSame(['blindfold_puzzle'], array_values(array_unique(array_column($review['items'], 'type'))));

        // Never rated: no puzzle attempt, no rating.
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM puzzle_attempt WHERE user_id = ?', $alice));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM puzzle_rating WHERE user_id = ?', $alice));

        $this->runOutbox();
        self::assertSame(3, $this->countRows("SELECT COUNT(*) FROM activity_log_entry WHERE user_id = ? AND exercise_type = 'blindfold_puzzle'", $alice));
        self::assertSame(12 + 6 + 2, $this->xpOf($alice));
        self::assertSame(20, $this->review($alice, $run['id'])['xp']);

        $state = $this->state($alice);
        self::assertSame(['played' => 3, 'solved' => 1, 'helped' => 1, 'failed' => 1], $state['total']);
        self::assertSame(3, $state['byLevel']['easy']['played'] ?? null);
        self::assertSame(0, $state['byLevel']['hard']['played'] ?? null);
        self::assertSame(3, $state['byLength'][2]['played'] ?? null);
        self::assertSame(PuzzleRules::toArray(), $state['rules']);

        $this->connection()->executeStatement('DELETE FROM gamification_xp_entry');
        $command = new CommandTester((new Application($this->client->getKernel()))->find('app:gamification:rebuild'));
        self::assertSame(0, $command->execute(['--user' => $alice->getId()->toRfc4122()]));
        self::assertSame(20, $this->xpOf($alice), 'The rebuild finds the same XP.');
    }

    public function testNoPuzzleTwiceInARunThenTheRangeRunsOut(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $expected = \count(array_filter(
            $this->selectablePuzzles(),
            static fn (Puzzle $p): bool => $p->getRating() >= 600 && $p->getRating() <= 1000 && \in_array('short', $p->getThemes(), true),
        ));
        self::assertGreaterThan(3, $expected);

        $run = $this->startOk($alice, self::EASY_SHORT);
        $seen = [];
        for ($i = 0; $i < $expected; ++$i) {
            $item = $this->item($alice, $run['id']);
            $seen[] = $item['data']['puzzle']['id'];
            $this->submitOk($alice, $run['id'], $item['id'], self::solution($item['data']));
        }
        self::assertCount($expected, array_unique($seen));

        // Nothing left in the range: the run closes.
        $step = $this->json($this->api('POST', '/api/training/runs/'.$run['id'].'/next', $alice));
        self::assertNull($step['item'] ?? null);
        self::assertSame('subject_unavailable', $this->getRun($alice, $run['id'])['closeReason']);

        // A new run serves puzzles already played rather than nothing.
        self::assertSame(201, $this->start($alice, self::EASY_SHORT)->getStatusCode());
    }

    public function testOptionsAreChecked(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        foreach ([
            [],
            ['level' => 'expert', 'length' => 2, 'visibleSeconds' => 10],
            ['level' => 'easy', 'length' => 1, 'visibleSeconds' => 10],
            ['level' => 'easy', 'length' => '2', 'visibleSeconds' => 10],
            ['level' => 'easy', 'length' => 2, 'visibleSeconds' => 7],
            [...self::EASY_SHORT, 'peeks' => 3],
        ] as $config) {
            self::assertSame(422, $this->start($alice, $config)->getStatusCode(), json_encode($config, \JSON_THROW_ON_ERROR));
        }
        $response = $this->api('POST', '/api/training/runs', $alice, ['module' => 'blindfold', 'subjectId' => $bob->getId()->toRfc4122(), 'budgetSeconds' => 600, 'config' => self::EASY_SHORT]);
        self::assertSame(404, $response->getStatusCode());

        // No puzzle of 4+ moves rated 1400–1800 among the samples: no run.
        self::assertSame(409, $this->start($alice, ['level' => 'hard', 'length' => 4, 'visibleSeconds' => 30])->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/training/runs/current', $alice)->getStatusCode());

        $run = $this->startOk($alice, ['level' => 'medium', 'length' => 3, 'visibleSeconds' => 30]);
        $item = $this->item($alice, $run['id']);
        self::assertContains('long', $item['data']['puzzle']['themes']);
        self::assertSame(400, $this->submit($alice, $run['id'], $item['id'], ['a1a1'])->getStatusCode(), 'Illegal move.');
        self::assertSame(404, $this->submit($alice, $run['id'], $run['id'], self::solution($item['data']))->getStatusCode());
    }

    public function testASessionStepNeedsNoCoordinates(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $steps = [['module' => 'blindfold', 'minutes' => 10, 'settings' => self::EASY_SHORT]];
        $session = $this->api('POST', '/api/training/sessions', $alice, ['steps' => $steps]);
        self::assertSame(201, $session->getStatusCode(), (string) $session->getContent());
    }

    /**
     * @param array<string, mixed> $config
     */
    private function start(User $user, array $config): Response
    {
        return $this->api('POST', '/api/training/runs', $user, ['module' => 'blindfold', 'subjectId' => $user->getId()->toRfc4122(), 'budgetSeconds' => 600, 'config' => $config]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return RunJson
     */
    private function startOk(User $user, array $config): array
    {
        $response = $this->start($user, $config);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    /**
     * @return BlindfoldItem
     */
    private function item(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $item = $this->json($response)['item'] ?? null;
        self::assertIsArray($item, 'The run served no item.');

        /** @var BlindfoldItem */
        return $item;
    }

    /**
     * @param list<string> $moves
     */
    private function submit(User $user, string $runId, string $itemId, array $moves): Response
    {
        return $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId, 'moves' => $moves]);
    }

    /**
     * @param list<string> $moves
     *
     * @return array{itemId: string, success: bool, data: array<string, mixed>}
     */
    private function submitOk(User $user, string $runId, string $itemId, array $moves): array
    {
        $response = $this->submit($user, $runId, $itemId, $moves);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var array{itemId: string, success: bool, data: array<string, mixed>} */
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
     * @return array{xp: int, items: list<array{type: string, status: string, durationMs: int|null, data: array<string, mixed>}>}
     */
    private function review(User $user, string $runId): array
    {
        $response = $this->api('GET', '/api/training/runs/'.$runId.'/review', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{xp: int, items: list<array{type: string, status: string, durationMs: int|null, data: array<string, mixed>}>} */
        return $this->json($response);
    }

    /**
     * @return array{rules: array<string, mixed>, total: array<string, int>, byLevel: array<string, array<string, int>>, byLength: array<int|string, array<string, int>>}
     */
    private function state(User $user): array
    {
        $response = $this->api('GET', '/api/blindfold/puzzles', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{rules: array<string, mixed>, total: array<string, int>, byLevel: array<string, array<string, int>>, byLength: array<int|string, array<string, int>>} */
        return $this->json($response);
    }

    /**
     * A legal first move that is neither the solution nor a mate.
     *
     * @param BlindfoldItem $item
     */
    private static function wrongFirstMove(array $item): string
    {
        $puzzle = $item['data']['puzzle'];
        $chess = new Chess($puzzle['fen']);
        $opening = $puzzle['moves'][0];
        $chess->move(['from' => substr($opening, 0, 2), 'to' => substr($opening, 2, 2), 'promotion' => substr($opening, 4, 1) ?: null]);
        foreach ($chess->moves() as $move) {
            $uci = $move->from.$move->to.($move->promotion ?? '');
            $chess->move(['from' => $move->from, 'to' => $move->to, 'promotion' => $move->promotion]);
            $mates = $chess->inCheckmate();
            $chess->undo();
            if ($uci !== $puzzle['moves'][1] && !$mates) {
                return $uci;
            }
        }

        self::fail('No wrong move available.');
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
