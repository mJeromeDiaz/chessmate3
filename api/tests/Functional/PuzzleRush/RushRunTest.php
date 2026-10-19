<?php

declare(strict_types=1);

namespace App\Tests\Functional\PuzzleRush;

use App\Entity\Catalog\Puzzle;
use App\Entity\User;
use App\Gamification\Xp\XpRules;
use App\Puzzle\Selection\SelectionRebuilder;
use App\PuzzleRush\RushRules;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use PChess\Chess\Chess;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puzzle rushes (docs/PUZZLE_RUSH.md): 2, 3 or 5 minutes, one try per puzzle, a miss does not stop
 * the run, the puzzle on screen at the end is not counted; score = solved − 2 × failed; never
 * rated; one exercise per run, its XP by the score (and the accuracy bonus) once; records of the
 * ranked runs only; a session step.
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 *
 * @phpstan-type PuzzleJson array{id: string, fen: string, moves: list<string>, playerColor: string, rating: int, themes: list<string>, gameUrl: string}
 * @phpstan-type RushItem array{id: string, type: string, data: array{puzzle: PuzzleJson, targetRating: int, position: int, startedAt: string, solved: int, failed: int}}
 * @phpstan-type ResultJson array{itemId: string, success: bool, data: array{status: string, mistakes: int, durationMs: int|null, solved: int, failed: int}}
 */
final class RushRunTest extends WoodpeckerWebTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Enough puzzles around the ramp of an unrated player (1500 − 500 = 1000, then +30 each).
        $this->addPuzzles('RU', 80, 960, 10, ['short']);
    }

    public function testARushRampsUpGoesOnAfterAMissAndScoresPrecision(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, [], 180);

        for ($i = 0; $i < 5; ++$i) {
            $item = $this->item($alice, $run['id']);
            self::assertSame(['rush_puzzle', $i, $i, 0], [$item['type'], $item['data']['position'], $item['data']['solved'], $item['data']['failed']]);
            self::assertSame(RushRules::target(RushRules::start(1500), $i), $item['data']['targetRating'], 'harder with each puzzle solved');
            self::assertSame($item['id'], $this->item($alice, $run['id'])['id'], 'A reload gets the same puzzle.');
            $this->travel('+5 seconds');
            $result = $this->submitOk($alice, $run['id'], $item['id'], self::solution($item['data']));
            self::assertSame([true, 'solved', $i + 1, 0], [$result['success'], $result['data']['status'], $result['data']['solved'], $result['data']['failed']]);
            self::assertArrayNotHasKey('score', $result['data'], 'no score while playing');
        }

        // A miss: counted, no second chance, the run goes on with a puzzle of the same level.
        $missed = $this->item($alice, $run['id']);
        $this->travel('+5 seconds');
        $result = $this->submitOk($alice, $run['id'], $missed['id'], [self::wrongFirstMove($missed)]);
        self::assertSame([false, 'failed', 5, 1], [$result['success'], $result['data']['status'], $result['data']['solved'], $result['data']['failed']]);
        self::assertSame(409, $this->submit($alice, $run['id'], $missed['id'], self::solution($missed['data']))->getStatusCode(), 'one try');
        $next = $this->item($alice, $run['id']);
        self::assertNotSame($missed['id'], $next['id']);
        self::assertSame($missed['data']['targetRating'], $next['data']['targetRating'], 'a miss does not raise the level');
        self::assertSame('active', $this->getRun($alice, $run['id'])['status']);

        // Time up: the puzzle on screen is not counted.
        $this->clock->modify($run['expiresAt']);
        $this->travel('+3 seconds');
        self::assertSame(409, $this->submit($alice, $run['id'], $next['id'], self::solution($next['data']))->getStatusCode(), 'too late');
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['closed', 'time_up'], [$closed['status'], $closed['closeReason']]);
        $summary = $closed['summary'];
        self::assertNotNull($summary);
        self::assertSame([6, 5, 180_000], [$summary['itemCount'], $summary['successCount'], $summary['durationMs']]);
        $metrics = $summary['metrics'];
        self::assertSame([5, 1, 0.83, 3, 6, true, null, true], [
            $metrics['solved'] ?? null, $metrics['failed'] ?? null, $metrics['accuracy'] ?? null, $metrics['score'] ?? null,
            $metrics['xp'] ?? null, $metrics['ranked'] ?? null, self::field($metrics, 'recordBefore'), $metrics['record'] ?? null,
        ]);
        self::assertEqualsWithDelta(5 / 3, $metrics['puzzlesPerMinute'] ?? null, 0.01);
        self::assertSame(5_000, $metrics['averageMs'] ?? null);

        $review = $this->review($alice, $run['id']);
        self::assertSame(['ok', 'ok', 'ok', 'ok', 'ok', 'fail'], array_column($review['items'], 'status'));
        self::assertSame($missed['data']['puzzle']['moves'], $review['items'][5]['data']['puzzle']['moves'] ?? null, 'the missed puzzle can be replayed');

        // Never rated.
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM puzzle_attempt WHERE user_id = ?', $alice));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM puzzle_rating WHERE user_id = ?', $alice));

        // One exercise for the run; its XP by the score.
        $this->runOutbox();
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM activity_log_entry WHERE user_id = ? AND exercise_type = 'puzzle_rush'", $alice));
        self::assertSame(6, $this->countRows("SELECT item_count FROM activity_log_entry WHERE user_id = ? AND exercise_type = 'puzzle_rush'", $alice));
        self::assertSame(XpRules::RUSH_PER_POINT * 3, $this->xpOf($alice));
        self::assertSame(XpRules::RUSH_PER_POINT * 3, $this->review($alice, $run['id'])['xp']);

        $overview = $this->overview($alice);
        self::assertSame(RushRules::toArray(), $overview['rules']);
        self::assertSame([[120, null, 0], [180, 3, 1], [300, null, 0]], array_map(
            static fn (array $record): array => [$record['budgetSeconds'], $record['best'], $record['runs']],
            $overview['records'],
        ));
        self::assertSame([[$run['id'], 3, 5, 1, 0.83, true]], array_map(
            static fn (array $recent): array => [$recent['id'], $recent['score'], $recent['solved'], $recent['failed'], $recent['accuracy'], $recent['ranked']],
            $overview['recent'],
        ));
    }

    public function testAHintTheSolutionOrASkipIsAMiss(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, [], 120);
        $item = $this->item($alice, $run['id']);
        $hinted = $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $item['id'], 'moves' => self::solution($item['data']), 'hintLevel' => 1]);
        self::assertSame('failed', $this->statusOf($hinted));
        $item = $this->item($alice, $run['id']);
        $shown = $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $item['id'], 'moves' => self::solution($item['data']), 'solutionShown' => true]);
        self::assertSame('failed', $this->statusOf($shown));
        $item = $this->item($alice, $run['id']);
        self::assertSame('failed', $this->statusOf($this->submit($alice, $run['id'], $item['id'], [])), 'skipped');
        self::assertSame([0, 3], [$this->item($alice, $run['id'])['data']['solved'], $this->item($alice, $run['id'])['data']['failed']]);
    }

    public function testASubmissionJustAfterTheEndIsAcceptedThenTheRunCloses(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, [], 120);
        $item = $this->item($alice, $run['id']);
        $this->clock->modify($run['expiresAt']);
        $this->travel('+1500 milliseconds');
        $result = $this->submitOk($alice, $run['id'], $item['id'], self::solution($item['data']));
        self::assertTrue($result['success'], 'within the 2 s network tolerance');
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['closed', 'time_up', 1], [$closed['status'], $closed['closeReason'], $closed['summary']['itemCount'] ?? null]);
        self::assertSame((new \DateTimeImmutable($run['expiresAt']))->getTimestamp(), (new \DateTimeImmutable((string) $closed['closedAt']))->getTimestamp());
    }

    public function testTheThinkTimeOnlyLowersTheServersAndTheAverageIsCapped(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, [], 300);
        $item = $this->item($alice, $run['id']);
        $this->travel('+10 seconds');
        $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $item['id'], 'moves' => self::solution($item['data']), 'thinkMs' => 3_000_000]);
        $item = $this->item($alice, $run['id']);
        $this->travel('+10 seconds');
        $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $item['id'], 'moves' => self::solution($item['data']), 'thinkMs' => 4_000]);
        $item = $this->item($alice, $run['id']);
        $this->travel('+150 seconds');
        $this->submitOk($alice, $run['id'], $item['id'], self::solution($item['data']));
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);

        self::assertSame([10_000, 4_000, 150_000], array_column($this->review($alice, $run['id'])['items'], 'durationMs'));
        self::assertSame(intdiv(10_000 + 4_000 + RushRules::AVERAGE_CAP_MS, 3), $this->getRun($alice, $run['id'])['summary']['metrics']['averageMs'] ?? null);
    }

    public function testOnlyRunsPlayedToTheEndWithoutThemesSetARecord(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        // Stopped early: counted (XP), not ranked.
        $stopped = $this->startOk($alice, [], 120);
        $this->solve($alice, $stopped['id'], 4);
        $this->api('POST', '/api/training/runs/'.$stopped['id'].'/stop', $alice);
        $metrics = $this->getRun($alice, $stopped['id'])['summary']['metrics'] ?? [];
        self::assertSame([4, false, false], [$metrics['score'] ?? null, $metrics['ranked'] ?? null, $metrics['record'] ?? null]);

        // With themes, played to the end: not ranked either.
        $themed = $this->startOk($alice, ['themes' => ['short']], 120);
        $this->solve($alice, $themed['id'], 2);
        $this->clock->modify($themed['expiresAt']);
        $this->travel('+5 seconds');
        $metrics = $this->getRun($alice, $themed['id'])['summary']['metrics'] ?? [];
        self::assertSame([['short'], false], [$metrics['themes'] ?? null, $metrics['ranked'] ?? null]);

        // Ranked: the first record, then one not beaten.
        $first = $this->playToTheEnd($alice, 2);
        self::assertSame([true, null], [$first['record'] ?? null, self::field($first, 'recordBefore')]);
        $second = $this->playToTheEnd($alice, 1);
        self::assertSame([false, 2], [$second['record'] ?? null, $second['recordBefore'] ?? null]);
        self::assertSame([2, null, null], array_column($this->overview($alice)['records'], 'best'));
        self::assertCount(4, $this->overview($alice)['recent']);

        $this->runOutbox();
        self::assertSame(XpRules::RUSH_PER_POINT * (4 + 2 + 2 + 1), $this->xpOf($alice));
    }

    public function testARushWithoutAPuzzleSolvedEarnsNothing(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, [], 120);
        $item = $this->item($alice, $run['id']);
        $this->submitOk($alice, $run['id'], $item['id'], [self::wrongFirstMove($item)]);
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        $metrics = $this->getRun($alice, $run['id'])['summary']['metrics'] ?? [];
        self::assertSame([0, 1, 0, 0], [$metrics['solved'] ?? null, $metrics['failed'] ?? null, $metrics['score'] ?? null, $metrics['xp'] ?? null]);
        self::assertEquals(0, $metrics['accuracy'] ?? null, 'played, none solved: 0 %, not null');
        self::assertNotNull($metrics['accuracy'] ?? null);

        // Nothing played at all: no accuracy, no exercise.
        $empty = $this->startOk($alice, [], 120);
        $this->api('POST', '/api/training/runs/'.$empty['id'].'/stop', $alice);
        $summary = $this->getRun($alice, $empty['id'])['summary'];
        self::assertSame([0, null], [$summary['itemCount'] ?? null, self::field($summary['metrics'] ?? [], 'accuracy')]);

        $this->runOutbox();
        self::assertSame(0, $this->xpOf($alice));
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM activity_log_entry WHERE user_id = ? AND exercise_type = 'puzzle_rush'", $alice));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM activity_log_entry WHERE user_id = ? AND exercise_type = 'puzzle_rush' AND success = 1", $alice));
    }

    public function testTheAccuracyBonusIsGainedOnceAndTheRebuildFindsIt(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, [], 300);
        $this->solve($alice, $run['id'], XpRules::RUSH_BONUS_MIN_SOLVED);
        $item = $this->item($alice, $run['id']);
        $this->submitOk($alice, $run['id'], $item['id'], [self::wrongFirstMove($item)]);
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        // 10 solved, 1 missed: 91 %, score 8.
        $xp = XpRules::RUSH_PER_POINT * 8 + XpRules::RUSH_ACCURACY_BONUS;
        self::assertSame([0.91, $xp], [$this->getRun($alice, $run['id'])['summary']['metrics']['accuracy'] ?? null, $this->getRun($alice, $run['id'])['summary']['metrics']['xp'] ?? null]);

        $this->runOutbox();
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        $this->runOutbox();
        self::assertSame($xp, $this->xpOf($alice), 'stopping again gains nothing more');
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM gamification_xp_entry WHERE user_id = ? AND module = 'puzzle_rush'", $alice));

        $this->connection()->executeStatement('DELETE FROM gamification_xp_entry');
        $command = new CommandTester((new Application($this->client->getKernel()))->find('app:gamification:rebuild'));
        self::assertSame(0, $command->execute(['--user' => $alice->getId()->toRfc4122()]));
        self::assertSame($xp, $this->xpOf($alice), 'The rebuild finds the same XP.');
    }

    public function testNothingToDrawRefusesTheRun(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        // Rated 3500: the ramp starts at 3000, where no mate in one is.
        $this->connection()->executeStatement(
            "INSERT INTO puzzle_rating (user_id, rating, deviation, volatility, rated_count, source, updated_at) VALUES (?, 3500, 150, 0.06, 0, 'lichess', UTC_TIMESTAMP())",
            [$alice->getId()->toBinary()],
        );
        self::assertSame(409, $this->start($alice, ['themes' => ['mateIn1']], 120)->getStatusCode());
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM training_run WHERE user_id = ? AND module = 'puzzle_rush'", $alice));
    }

    public function testOptionsAreChecked(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        foreach ([60, 240, 600] as $budget) {
            self::assertSame(422, $this->start($alice, [], $budget)->getStatusCode(), 'A rush lasts 2, 3 or 5 minutes.');
        }
        foreach ([
            ['level' => 'easy'],
            ['themes' => 'fork'],
            ['themes' => [1]],
            ['themes' => ['nope']],
            ['themes' => array_fill(0, RushRules::MAX_THEMES + 1, 'fork')],
        ] as $config) {
            self::assertSame(422, $this->start($alice, $config, 120)->getStatusCode(), json_encode($config, \JSON_THROW_ON_ERROR));
        }
        $response = $this->api('POST', '/api/training/runs', $bob, ['module' => 'puzzle_rush', 'subjectId' => $alice->getId()->toRfc4122(), 'budgetSeconds' => 120, 'config' => []]);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testASessionStepAndItsChestTierFollowThePrecision(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $refused = $this->api('POST', '/api/training/sessions', $alice, ['steps' => [['module' => 'puzzle_rush', 'minutes' => 10, 'settings' => []]]]);
        self::assertSame(422, $refused->getStatusCode(), 'a step lasts 2, 3 or 5 minutes');

        $session = $this->api('POST', '/api/training/sessions', $alice, ['steps' => [['module' => 'puzzle_rush', 'minutes' => 3, 'settings' => ['themes' => ['short']]]]]);
        self::assertSame(201, $session->getStatusCode(), (string) $session->getContent());
        $id = $this->json($session)['id'] ?? null;
        self::assertIsString($id);
        $launched = $this->api('POST', '/api/training/sessions/'.$id.'/next', $alice);
        self::assertSame(200, $launched->getStatusCode(), (string) $launched->getContent());
        $run = $this->json($launched)['run'] ?? null;
        self::assertIsArray($run);
        self::assertSame([180, 'puzzle_rush'], [$run['budgetSeconds'] ?? null, $run['module'] ?? null]);
        $runId = \is_string($run['id'] ?? null) ? $run['id'] : '';
        self::assertSame('rush_puzzle', $this->item($alice, $runId)['type']);

        // 2 solved out of 3: below 80 %, the lowest tier.
        $this->solve($alice, $runId, 2);
        $item = $this->item($alice, $runId);
        $this->submitOk($alice, $runId, $item['id'], [self::wrongFirstMove($item)]);
        $this->api('POST', '/api/training/runs/'.$runId.'/stop', $alice);
        $view = $this->json($this->api('GET', '/api/training/sessions/'.$id, $alice));
        self::assertSame('completed', $view['status'] ?? null);
        self::assertIsArray($view['reward'] ?? null);
        self::assertSame('missed', $view['reward']['tier'] ?? null);
    }

    /**
     * A ranked run of 2 minutes: $solved puzzles, then the time runs out.
     *
     * @return array<string, mixed> its metrics
     */
    private function playToTheEnd(User $user, int $solved): array
    {
        $run = $this->startOk($user, [], 120);
        $this->solve($user, $run['id'], $solved);
        $this->clock->modify($run['expiresAt']);
        $this->travel('+5 seconds');

        return $this->getRun($user, $run['id'])['summary']['metrics'] ?? [];
    }

    private function solve(User $user, string $runId, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $item = $this->item($user, $runId);
            $this->travel('+5 seconds');
            $this->submitOk($user, $runId, $item['id'], self::solution($item['data']));
        }
    }

    /**
     * Puzzles from $rating on, $step points apart, added to the catalogue and indexed for themed
     * draws. Black to move: the player has White, one move to find.
     *
     * @param list<string> $themes
     */
    private function addPuzzles(string $prefix, int $count, int $rating, int $step, array $themes): void
    {
        $added = [];
        for ($i = 0; $i < $count; ++$i) {
            $added[] = $entity = new Puzzle(\sprintf('%s%03d', $prefix, $i), 'r2q3r/pp1n1pk1/2pbbn1B/3pR3/3P3Q/3B4/PPP2PPP/RN4K1 b - - 9 16', 'g7g8 e5g5', $rating + $i * $step, 80, 95, 5000, $themes, 'https://lichess.org/abcdefgh', null);
            $this->catalog->persist($entity);
        }
        $this->catalog->flush();
        self::getContainer()->get(SelectionRebuilder::class)->addPuzzles(array_map(static fn (Puzzle $p): int => (int) $p->getId(), $added));
        $this->catalog->clear();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function start(User $user, array $config, int $budget): Response
    {
        return $this->api('POST', '/api/training/runs', $user, ['module' => 'puzzle_rush', 'subjectId' => $user->getId()->toRfc4122(), 'budgetSeconds' => $budget, 'config' => $config]);
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
     * @return RushItem
     */
    private function item(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $item = $this->json($response)['item'] ?? null;
        self::assertIsArray($item, 'The run served no item.');

        /** @var RushItem */
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
     * @return ResultJson
     */
    private function submitOk(User $user, string $runId, string $itemId, array $moves): array
    {
        $response = $this->submit($user, $runId, $itemId, $moves);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var ResultJson */
        return $result;
    }

    private function statusOf(Response $submission): string
    {
        self::assertSame(200, $submission->getStatusCode(), (string) $submission->getContent());
        $result = $this->json($submission)['result'] ?? null;
        self::assertIsArray($result);
        self::assertIsArray($result['data'] ?? null);
        self::assertIsString($result['data']['status'] ?? null);

        return $result['data']['status'];
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
     * @return array{xp: int, items: list<array{type: string, status: string, durationMs: int|null, data: array{puzzle?: PuzzleJson}}>}
     */
    private function review(User $user, string $runId): array
    {
        $response = $this->api('GET', '/api/training/runs/'.$runId.'/review', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{xp: int, items: list<array{type: string, status: string, durationMs: int|null, data: array{puzzle?: PuzzleJson}}>} */
        return $this->json($response);
    }

    /**
     * @return array{rules: array<string, mixed>, records: list<array{budgetSeconds: int, best: int|null, runs: int, at: string|null}>, recent: list<array{id: string, budgetSeconds: int, score: int, solved: int, failed: int, accuracy: float|null, puzzlesPerMinute: float|null, ranked: bool, themes: list<string>, closedAt: string}>}
     */
    private function overview(User $user): array
    {
        $response = $this->api('GET', '/api/puzzle-rush', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{rules: array<string, mixed>, records: list<array{budgetSeconds: int, best: int|null, runs: int, at: string|null}>, recent: list<array{id: string, budgetSeconds: int, score: int, solved: int, failed: int, accuracy: float|null, puzzlesPerMinute: float|null, ranked: bool, themes: list<string>, closedAt: string}>} */
        return $this->json($response);
    }

    /**
     * The value of a key, or 'missing' when absent: unlike `??`, a null value stays null.
     *
     * @param array<string, mixed> $array
     */
    private static function field(array $array, string $key): mixed
    {
        return \array_key_exists($key, $array) ? $array[$key] : 'missing';
    }

    /**
     * A legal first move that is neither the solution nor a mate.
     *
     * @param RushItem $item
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
