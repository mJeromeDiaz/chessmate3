<?php

declare(strict_types=1);

namespace App\Tests\Functional\Brilliancy;

use App\Brilliancy\BrilliancyRules;
use App\Brilliancy\Index\Indexer;
use App\Chess\Rules;
use App\Entity\Catalog\Puzzle;
use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * « Brillant ou Blunder » in timed runs (docs/BRILLIANCY.md) on the sample puzzles indexed (two
 * brilliant moves, two blunders): nothing of the answer before the verdict, the verdict and the
 * puzzle's line, the XP, a run that ends when the index runs out, the review, the overview, the
 * options and answers checked.
 *
 * @phpstan-type BrilliancyItem array{id: string, type: string, data: array{position: array{fen: string, color: string, move: array{uci: string, san: string}}, index: int, right: int, wrong: int}}
 * @phpstan-type BrilliancyResult array{itemId: string, success: bool, data: array{status: string, kind: string, answer: string, line: list<array{uci: string, san: string}>, puzzle: array{id: string, rating: int, gameUrl: string}|null, durationMs: int|null, right: int, wrong: int}}
 */
final class BrilliancyRunTest extends WoodpeckerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::getContainer()->get(Indexer::class)->index(array_values(array_map(static fn (Puzzle $puzzle): int => (int) $puzzle->getId(), $this->puzzles)));
    }

    public function testASacrificeIsJudgedThenItsLineShown(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice);
        $first = $this->item($alice, $run);
        self::assertSame(['brilliancy_position', 1, 0, 0], [$first['type'], $first['data']['index'], $first['data']['right'], $first['data']['wrong']]);
        self::assertSame(['position', 'servedAt', 'index', 'right', 'wrong'], array_keys($first['data']), 'Nothing of the answer.');
        $position = $first['data']['position'];
        self::assertNotNull(Rules::fromFen($position['fen'])->playUci($position['move']['uci']), 'A legal move of the position.');
        self::assertSame(Rules::fromFen($position['fen'])->sideToMove(), 'white' === $position['color'] ? 'w' : 'b');

        $this->travel('+5 seconds');
        $kind = $this->kindOf($first['id']);
        $right = $this->submitOk($alice, $run, $first['id'], $kind, 4);
        self::assertSame([true, 'right', $kind, $kind, 5_000, 1, 0], [$right['success'], $right['data']['status'], $right['data']['kind'], $right['data']['answer'], $right['data']['durationMs'], $right['data']['right'], $right['data']['wrong']]);
        $line = $right['data']['line'];
        self::assertSame($position['move']['uci'], $line[0]['uci'] ?? null, 'The line starts with the move shown.');
        self::assertGreaterThanOrEqual('blunder' === $kind ? 2 : 1, \count($line));
        self::assertIsArray($right['data']['puzzle']);

        $second = $this->item($alice, $run);
        self::assertSame([2, 1], [$second['data']['index'], $second['data']['right']]);
        self::assertNotSame($position['fen'], $second['data']['position']['fen'], 'Never twice in a run.');
        $other = 'brilliant' === $this->kindOf($second['id']) ? 'blunder' : 'brilliant';
        $wrong = $this->submitOk($alice, $run, $second['id'], $other, 1);
        self::assertSame([false, 'wrong', $other, 1, 1], [$wrong['success'], $wrong['data']['status'], $wrong['data']['answer'], $wrong['data']['right'], $wrong['data']['wrong']]);
        self::assertSame(409, $this->submit($alice, $run, $second['id'], $other)->getStatusCode(), 'Answered once.');

        // The position on screen when the time is up is not counted.
        $third = $this->item($alice, $run);
        $this->travel('+10 minutes');
        $closed = $this->getRun($alice, $run);
        self::assertSame(['time_up', 2, 1], [$closed['closeReason'], $closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null]);
        $metrics = $closed['summary']['metrics'] ?? null;
        self::assertIsArray($metrics);
        self::assertSame([1, 1], [$metrics['right'] ?? null, $metrics['wrong'] ?? null]);
        self::assertFalse($this->connection()->fetchOne('SELECT 1 FROM brilliancy_attempt WHERE id = ?', [Uuid::fromString($third['id'])->toBinary()]));

        $this->runOutbox();
        self::assertSame(4 + 1, $this->xpOf($alice));

        $review = $this->api('GET', '/api/training/runs/'.$run.'/review', $alice);
        self::assertSame(200, $review->getStatusCode(), (string) $review->getContent());
        $items = $this->json($review)['items'] ?? null;
        self::assertIsArray($items);
        self::assertSame(['ok', 'fail'], array_column($items, 'status'));

        $overview = $this->json($this->api('GET', '/api/brilliancy', $alice));
        self::assertSame(['brilliant' => 2, 'blunder' => 2], $overview['positions'] ?? null);
        $results = $overview['results'] ?? null;
        self::assertIsArray($results);
        self::assertSame([2, 1, 1], [$results['played'] ?? null, $results['right'] ?? null, $results['wrong'] ?? null]);
        self::assertSame(['runMinutes' => BrilliancyRules::RUN_MINUTES, 'defaultMinutes' => BrilliancyRules::DEFAULT_MINUTES], $overview['rules'] ?? null);
    }

    public function testTheRunClosesWhenNoSacrificeIsLeft(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice);
        $kinds = [];
        for ($i = 0; $i < 4; ++$i) {
            $item = $this->item($alice, $run);
            $kinds[] = $this->kindOf($item['id']);
            $this->submitOk($alice, $run, $item['id'], 'brilliant');
        }
        sort($kinds);
        self::assertSame(['blunder', 'blunder', 'brilliant', 'brilliant'], $kinds, 'Both kinds, every position once.');

        $response = $this->api('POST', '/api/training/runs/'.$run.'/next', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $closed = $this->getRun($alice, $run);
        self::assertSame(['subject_unavailable', 4, 2], [$closed['closeReason'], $closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null]);
    }

    public function testAnEmptyIndexRefusesTheRun(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        self::getContainer()->get('doctrine.dbal.catalog_connection')->executeStatement('DELETE FROM brilliancy_position');

        self::assertSame(409, $this->start($alice)->getStatusCode());
    }

    public function testOptionsLengthsAndAnswersAreChecked(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        self::assertSame(422, $this->start($alice, ['elo' => 1500])->getStatusCode(), 'No option.');
        // 1, 2, 5 or 10 minutes only.
        self::assertSame(422, $this->start($alice, [], 180)->getStatusCode());

        $run = $this->startOk($alice);
        $item = $this->item($alice, $run);
        self::assertSame(400, $this->api('POST', '/api/training/runs/'.$run.'/submission', $alice, ['itemId' => $item['id']])->getStatusCode(), 'No answer.');
        self::assertSame(400, $this->submit($alice, $run, $item['id'], 'maybe')->getStatusCode());
        self::assertSame(422, $this->submit($alice, $run, $item['id'], 'not a kind!')->getStatusCode(), 'Not even a word.');
        self::assertSame('right', $this->submitOk($alice, $run, $item['id'], $this->kindOf($item['id']))['data']['status']);
    }

    /**
     * The kind of a position served, as stored (never sent before the verdict).
     */
    private function kindOf(string $itemId): string
    {
        $kind = $this->connection()->fetchOne('SELECT kind FROM brilliancy_attempt WHERE id = ?', [Uuid::fromString($itemId)->toBinary()]);
        self::assertIsString($kind);

        return $kind;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function start(User $user, array $config = [], int $budgetSeconds = 120): Response
    {
        return $this->api('POST', '/api/training/runs', $user, ['module' => 'brilliancy', 'subjectId' => $user->getId()->toRfc4122(), 'budgetSeconds' => $budgetSeconds, 'config' => (object) $config]);
    }

    private function startOk(User $user): string
    {
        $response = $this->start($user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->json($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return BrilliancyItem
     */
    private function item(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $item = $this->json($response)['item'] ?? null;
        self::assertIsArray($item, 'The run served no position.');

        /** @var BrilliancyItem */
        return $item;
    }

    private function submit(User $user, string $runId, string $itemId, string $choice): Response
    {
        return $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId, 'choice' => $choice]);
    }

    /**
     * @param int|null $xp the XP the response must announce, if checked
     *
     * @return BrilliancyResult
     */
    private function submitOk(User $user, string $runId, string $itemId, string $choice, ?int $xp = null): array
    {
        $response = $this->submit($user, $runId, $itemId, $choice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $step = $this->json($response);
        if (null !== $xp) {
            self::assertSame($xp, $step['xp'] ?? null);
        }
        $result = $step['result'] ?? null;
        self::assertIsArray($result);

        /** @var BrilliancyResult */
        return $result;
    }

    /**
     * @return array{closeReason: string|null, summary: array<string, mixed>|null}
     */
    private function getRun(User $user, string $runId): array
    {
        $response = $this->api('GET', '/api/training/runs/'.$runId, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{closeReason: string|null, summary: array<string, mixed>|null} */
        return $this->json($response);
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
