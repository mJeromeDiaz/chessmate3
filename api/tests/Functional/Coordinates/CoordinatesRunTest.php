<?php

declare(strict_types=1);

namespace App\Tests\Functional\Coordinates;

use App\Coordinates\Series\CoordinateRules;
use App\Entity\User;
use App\Gamification\Xp\XpRules;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * The coordinates series in timed runs (docs/COORDINATES.md): squares drawn at the start, answers
 * sent in batches and judged again by the server, validation per orientation at the closing, XP.
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 *
 * @phpstan-type SeriesItem array{id: string, type: string, data: array{orientation: string, squares: list<string>, answered: int, successCount: int, rules: array<string, mixed>}}
 * @phpstan-type Answer array{index: int, square: string, ms: int}
 */
final class CoordinatesRunTest extends WoodpeckerWebTestCase
{
    public function testASeriesPlayedToItsEndValidatesItsOrientationOnce(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startSeries($alice, 'white');
        $item = $this->item($alice, $run['id']);

        self::assertSame(['coordinates_series', 'white', 0], [$item['type'], $item['data']['orientation'], $item['data']['answered']]);
        self::assertSame(CoordinateRules::toArray(), $item['data']['rules']);
        $squares = $item['data']['squares'];
        self::assertCount(CoordinateRules::SQUARES_PER_SERIES, $squares);
        foreach ($squares as $i => $square) {
            self::assertMatchesRegularExpression('/^[a-h][1-8]$/', $square);
            self::assertNotSame($squares[$i - 1] ?? null, $square, 'Never the same square twice in a row.');
        }

        $this->travel('+70 seconds');
        $result = $this->submitOk($alice, $run['id'], $item['id'], self::answers($squares, 0, 60));
        self::assertSame([true, 60, 60], [$result['success'], $result['data']['answerCount'] ?? null, $result['data']['successCount'] ?? null]);
        self::assertSame(200, $this->runNextResponse($alice, $run['id'])->getStatusCode());
        self::assertSame(60, $this->item($alice, $run['id'])['data']['answered'], 'A reload goes on after the answers judged.');

        $this->travel('+4 minutes');
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['time_up', 60, 60], [$closed['closeReason'], $closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null]);
        self::assertSame(['white', true, 60_000, 1_000], [
            $closed['summary']['metrics']['orientation'] ?? null,
            $closed['summary']['metrics']['validated'] ?? null,
            $closed['summary']['metrics']['answeredMs'] ?? null,
            $closed['summary']['metrics']['averageMs'] ?? null,
        ]);

        $this->runOutbox();
        self::assertSame([['coordinates_series', true, 300_000, 60, 'coordinates_series', '2026-09-28 10:05:00']], $this->exercises($alice));
        self::assertSame(20 + 100, $this->xpOf($alice), 'The series and the first validation of White.');
        self::assertSame(1, $this->validationBonuses($alice));
        $review = $this->review($alice, $run['id']);
        self::assertSame(120, $review['xp'], 'The bonus belongs to the run.');
        self::assertCount(60, $review['items']);
        self::assertSame(['coordinate', 'ok', 1_000, ['index' => 0, 'target' => $squares[0], 'clicked' => $squares[0]]], [
            $review['items'][0]['type'], $review['items'][0]['status'], $review['items'][0]['durationMs'], $review['items'][0]['data'],
        ]);

        $state = $this->coordinates($alice);
        self::assertSame([true, false], array_column($state['orientations'], 'validated'));
        self::assertSame([1, 0], array_column($state['orientations'], 'seriesCount'));
        self::assertSame(60, $state['orientations'][0]['best']['successCount'] ?? null);
        self::assertNull($state['orientations'][1]['best']);
        self::assertSame([$run['id']], array_column($state['history'], 'runId'));

        // White again: the series gains, the validation does not.
        $this->playFullSeries($alice, 'white', 60);
        $this->runOutbox();
        self::assertSame(120 + 20, $this->xpOf($alice));

        // Black validated too: its own bonus.
        $this->playFullSeries($alice, 'black', 60);
        $this->runOutbox();
        self::assertSame(140 + 20 + 100, $this->xpOf($alice));
        self::assertSame(2, $this->validationBonuses($alice), 'Once per orientation.');
        $state = $this->coordinates($alice);
        self::assertSame([true, true], array_column($state['orientations'], 'validated'));
        self::assertSame('2026-09-28T10:05:00+00:00', $state['orientations'][0]['validatedAt'], 'The first validation.');
        self::assertCount(3, $state['history']);

        // The rebuild finds the same XP.
        $this->connection()->executeStatement('DELETE FROM gamification_xp_entry');
        $command = new CommandTester((new Application($this->client->getKernel()))->find('app:gamification:rebuild'));
        self::assertSame(0, $command->execute(['--user' => $alice->getId()->toRfc4122()]));
        self::assertSame(260, $this->xpOf($alice));
    }

    public function testWhatDoesNotValidate(): void
    {
        $alice = $this->createUserIn('alice@example.com');

        $tooFew = $this->playFullSeries($alice, 'white', CoordinateRules::MIN_ANSWERS - 1);
        // 56 / 60 = 93 %.
        $tooManyMistakes = $this->playFullSeries($alice, 'white', 60, [3, 10, 20, 30]);

        $stopped = $this->startSeries($alice, 'white');
        $item = $this->item($alice, $stopped['id']);
        $this->travel('+70 seconds');
        $this->submitOk($alice, $stopped['id'], $item['id'], self::answers($item['data']['squares'], 0, 60));
        $this->api('POST', '/api/training/runs/'.$stopped['id'].'/stop', $alice);

        $short = $this->playFullSeries($alice, 'white', XpRules::COORDINATES_MIN_ANSWERS - 1);

        foreach ([$tooFew, $tooManyMistakes, $stopped['id'], $short] as $runId) {
            self::assertFalse($this->getRun($alice, $runId)['summary']['metrics']['validated'] ?? null, $runId);
        }
        self::assertSame('stopped', $this->getRun($alice, $stopped['id'])['closeReason']);
        $this->runOutbox();
        self::assertSame([false, false, false, false], array_column($this->exercises($alice), 1), 'Every series is an exercise, none a success.');
        self::assertSame(3 * 20, $this->xpOf($alice), 'Nothing for a series of fewer than 10 answers.');
        self::assertSame(0, $this->validationBonuses($alice));
        $state = $this->coordinates($alice);
        self::assertSame([false, false], array_column($state['orientations'], 'validated'));
        self::assertSame(4, $state['orientations'][0]['seriesCount']);
    }

    public function testTheServerJudgesTheAnswersAgain(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startSeries($alice, 'black');
        $item = $this->item($alice, $run['id']);
        $squares = $item['data']['squares'];
        $this->travel('+10 seconds');

        $result = $this->submitOk($alice, $run['id'], $item['id'], [
            ['index' => 0, 'square' => $squares[0], 'ms' => 1_000],
            ['index' => 1, 'square' => self::wrong($squares[1]), 'ms' => 1_000],
        ]);
        self::assertFalse($result['success']);
        self::assertSame([['index' => 0, 'correct' => true], ['index' => 1, 'correct' => false]], $result['data']['results'] ?? null);

        // A batch sent again keeps its first verdicts and adds the new answers only.
        $result = $this->submitOk($alice, $run['id'], $item['id'], self::answers($squares, 1, 2));
        self::assertSame([['index' => 1, 'correct' => false], ['index' => 2, 'correct' => true]], $result['data']['results'] ?? null);
        self::assertSame([3, 2], [$result['data']['answerCount'] ?? null, $result['data']['successCount'] ?? null]);

        $refused = [
            'a gap' => [400, $item['id'], self::answers($squares, 4, 1)],
            'not in order' => [400, $item['id'], [...self::answers($squares, 3, 1), ...self::answers($squares, 5, 1)]],
            'nothing' => [400, $item['id'], []],
            'not a square' => [422, $item['id'], [['index' => 3, 'square' => 'z9', 'ms' => 1_000]]],
            'beyond the squares drawn' => [422, $item['id'], [['index' => CoordinateRules::SQUARES_PER_SERIES, 'square' => 'a1', 'ms' => 1_000]]],
            'too many' => [422, $item['id'], self::answers($squares, 3, CoordinateRules::MAX_ANSWERS_PER_SUBMISSION + 1)],
            'another item' => [404, $run['id'], self::answers($squares, 3, 1)],
        ];
        foreach ($refused as $case => [$status, $itemId, $answers]) {
            self::assertSame($status, $this->submit($alice, $run['id'], $itemId, $answers)->getStatusCode(), $case);
        }

        // 10 s elapsed, 3 s already answered: the client cannot claim more than the 7 s left.
        $this->submitOk($alice, $run['id'], $item['id'], [['index' => 3, 'square' => $squares[3], 'ms' => 3_000_000]]);
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame([4, 3, 10_000], [$closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null, $closed['summary']['metrics']['answeredMs'] ?? null]);

        $review = $this->review($alice, $run['id']);
        self::assertSame(['ok', 'fail', 'ok', 'ok'], array_column($review['items'], 'status'));
        self::assertSame(['target' => $squares[1], 'clicked' => self::wrong($squares[1])], array_intersect_key($review['items'][1]['data'], ['target' => 1, 'clicked' => 1]));
    }

    public function testALateBatchIsRefused(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startSeries($alice, 'white');
        $item = $this->item($alice, $run['id']);
        $squares = $item['data']['squares'];

        $this->travel('+301 seconds');
        $this->submitOk($alice, $run['id'], $item['id'], self::answers($squares, 0, 10));
        $this->travel('+2 seconds');
        self::assertSame(409, $this->submit($alice, $run['id'], $item['id'], self::answers($squares, 10, 10))->getStatusCode());

        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['time_up', 10, '2026-09-28T10:05:00+00:00'], [$closed['closeReason'], $closed['summary']['itemCount'] ?? null, $closed['closedAt']]);
    }

    public function testASeriesLastsItsFixedTimeAndItsOptionsAreChecked(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        $refused = [
            'too long' => [422, $alice, 600, ['orientation' => 'white']],
            'too short' => [422, $alice, 60, ['orientation' => 'white']],
            'no orientation' => [422, $alice, CoordinateRules::SERIES_SECONDS, []],
            'unknown orientation' => [422, $alice, CoordinateRules::SERIES_SECONDS, ['orientation' => 'red']],
            'unknown option' => [422, $alice, CoordinateRules::SERIES_SECONDS, ['orientation' => 'white', 'speed' => 2]],
            "another user's subject" => [404, $bob, CoordinateRules::SERIES_SECONDS, ['orientation' => 'white']],
        ];
        foreach ($refused as $case => [$status, $subject, $budget, $config]) {
            $response = $this->api('POST', '/api/training/runs', $alice, ['module' => 'coordinates', 'subjectId' => $subject->getId()->toRfc4122(), 'budgetSeconds' => $budget, 'config' => $config]);
            self::assertSame($status, $response->getStatusCode(), $case);
        }

        // In a session: exactly the series' length.
        $step = ['module' => 'coordinates', 'minutes' => 10, 'settings' => ['orientation' => 'black']];
        self::assertSame(422, $this->api('POST', '/api/training/sessions', $alice, ['steps' => [$step]])->getStatusCode());
        $step['minutes'] = intdiv(CoordinateRules::SERIES_SECONDS, 60);
        $session = $this->api('POST', '/api/training/sessions', $alice, ['steps' => [$step]]);
        self::assertSame(201, $session->getStatusCode(), (string) $session->getContent());
        $sessionId = $this->json($session)['id'] ?? null;
        self::assertIsString($sessionId);
        $next = $this->json($this->api('POST', '/api/training/sessions/'.$sessionId.'/next', $alice));
        self::assertIsArray($next['run'] ?? null);
        self::assertSame('coordinates', $next['run']['module'] ?? null);
        self::assertIsString($next['run']['id'] ?? null);
        self::assertSame('black', $this->item($alice, $next['run']['id'])['data']['orientation']);
    }

    public function testTheStateIsPrivate(): void
    {
        $this->client->request('GET', '/api/coordinates');
        self::assertSame(401, $this->client->getResponse()->getStatusCode());

        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        $this->playFullSeries($alice, 'white', 60);
        $state = $this->coordinates($bob);
        self::assertSame([[], [0, 0]], [$state['history'], array_column($state['orientations'], 'seriesCount')]);
    }

    /**
     * Plays a whole series: $count answers, right except at $wrongAt, then lets the time run out.
     *
     * @param list<int> $wrongAt
     *
     * @return string the run id
     */
    private function playFullSeries(User $user, string $orientation, int $count, array $wrongAt = []): string
    {
        $run = $this->startSeries($user, $orientation);
        $item = $this->item($user, $run['id']);
        $this->travel('+150 seconds');
        $answers = array_map(
            static fn (array $answer): array => \in_array($answer['index'], $wrongAt, true) ? ['index' => $answer['index'], 'square' => self::wrong($answer['square']), 'ms' => $answer['ms']] : $answer,
            self::answers($item['data']['squares'], 0, $count),
        );
        if ([] !== $answers) {
            $this->submitOk($user, $run['id'], $item['id'], $answers);
        }
        $this->travel('+160 seconds');
        self::assertSame('time_up', $this->getRun($user, $run['id'])['closeReason']);

        return $run['id'];
    }

    /**
     * @return RunJson
     */
    private function startSeries(User $user, string $orientation): array
    {
        $response = $this->api('POST', '/api/training/runs', $user, [
            'module' => 'coordinates',
            'subjectId' => $user->getId()->toRfc4122(),
            'budgetSeconds' => CoordinateRules::SERIES_SECONDS,
            'config' => ['orientation' => $orientation],
        ]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    /**
     * @return SeriesItem
     */
    private function item(User $user, string $runId): array
    {
        $item = $this->json($this->runNextResponse($user, $runId))['item'] ?? null;
        self::assertIsArray($item, 'The run served no item.');

        /** @var SeriesItem */
        return $item;
    }

    private function runNextResponse(User $user, string $runId): Response
    {
        return $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
    }

    /**
     * @param list<Answer> $answers
     */
    private function submit(User $user, string $runId, string $itemId, array $answers): Response
    {
        return $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId, 'answers' => $answers]);
    }

    /**
     * @param list<Answer> $answers
     *
     * @return array{itemId: string, success: bool, data: array<string, mixed>}
     */
    private function submitOk(User $user, string $runId, string $itemId, array $answers): array
    {
        $response = $this->submit($user, $runId, $itemId, $answers);
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
     * @return array{rules: array<string, mixed>, orientations: list<array{orientation: string, validated: bool, validatedAt: string|null, seriesCount: int, best: array<string, mixed>|null}>, history: list<array<string, mixed>>}
     */
    private function coordinates(User $user): array
    {
        $response = $this->api('GET', '/api/coordinates', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{rules: array<string, mixed>, orientations: list<array{orientation: string, validated: bool, validatedAt: string|null, seriesCount: int, best: array<string, mixed>|null}>, history: list<array<string, mixed>>} */
        return $this->json($response);
    }

    /**
     * Right answers to $count squares from $from, a second each.
     *
     * @param list<string> $squares
     *
     * @return list<Answer>
     */
    private static function answers(array $squares, int $from, int $count): array
    {
        $answers = [];
        for ($i = $from; $i < $from + $count; ++$i) {
            $answers[] = ['index' => $i, 'square' => $squares[$i] ?? 'a1', 'ms' => 1_000];
        }

        return $answers;
    }

    private static function wrong(string $square): string
    {
        return 'a1' === $square ? 'h8' : 'a1';
    }

    /**
     * The user's exercises in the activity log: type, success, duration, items, source, instant (UTC).
     *
     * @return list<array{string, bool, int, int, string, string}>
     */
    private function exercises(User $user): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT exercise_type, success, duration_ms, item_count, source_type, occurred_at FROM activity_log_entry WHERE user_id = ? ORDER BY occurred_at',
            [$user->getId()->toBinary()],
        );

        $text = static fn (mixed $value): string => \is_string($value) ? $value : throw new \UnexpectedValueException('Not a string.');
        $number = static fn (mixed $value): int => is_numeric($value) ? (int) $value : throw new \UnexpectedValueException('Not a number.');

        return array_map(static fn (array $row): array => [
            $text($row['exercise_type']), (bool) $row['success'], $number($row['duration_ms']), $number($row['item_count']), $text($row['source_type']), $text($row['occurred_at']),
        ], $rows);
    }

    private function validationBonuses(User $user): int
    {
        $count = $this->connection()->fetchOne("SELECT COUNT(*) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'validation'", [$user->getId()->toBinary()]);
        self::assertTrue(is_numeric($count));

        return (int) $count;
    }

    private function xpOf(User $user): int
    {
        $sum = $this->connection()->fetchOne('SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ?', [$user->getId()->toBinary()]);
        self::assertTrue(is_numeric($sum));

        return (int) $sum;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
