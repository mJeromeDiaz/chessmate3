<?php

declare(strict_types=1);

namespace App\Tests\Functional\Evaluation;

use App\Entity\Evaluation\Position;
use App\Entity\User;
use App\Enum\Evaluation\Plan;
use App\Enum\Evaluation\PositionTag;
use App\Enum\Repertoire\Color;
use App\Evaluation\EvaluationRules;
use App\Gamification\Xp\XpRules;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Position evaluation in timed runs (docs/EVALUATION.md): no correction before the answer, a
 * deadline per position on the server's clock, verdicts and XP, the run closing after its
 * positions, the choice of positions, a plan asked only when there is one. On the 6 positions of
 * POSITIONS.
 *
 * @phpstan-type EvalItem array{id: string, type: string, data: array{position: array{fen: string, turn: string, tag: string|null, tagLabel: string|null}, tip: string|null, askPlan: bool, seconds: int, servedAt: string, deadlineAt: string, index: int, count: int, plans: list<array{value: string, label: string}>}}
 */
final class EvaluationRunTest extends WoodpeckerWebTestCase
{
    private const CONFIG = ['count' => 3, 'seconds' => 60, 'elo' => 1200, 'side' => 'both'];

    /** source => [FEN, evaluation, plan, rating]: 3 with White to move, 3 with Black. */
    private const POSITIONS = [
        'lucena' => ['1K1k4/1P6/8/8/8/8/r7/2R5 w - - 0 1', 10_000, Plan::Simplify, 1400],
        'king-pawn-draw' => ['8/8/4k3/8/8/4K3/4P3/8 w - - 0 1', 0, Plan::ActiveDefense, 1000],
        'start' => ['rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1', 20, Plan::CenterSpace, 1000],
        'after-e4' => ['rnbqkbnr/pppppppp/8/8/4P3/8/PPPP1PPP/RNBQKBNR b KQkq - 0 1', 30, null, 1100],
        'black-better' => ['rnbqkbnr/pppp1ppp/8/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R b KQkq - 0 1', -150, Plan::KingAttack, 2000],
        'black-wins' => ['r1bqkbnr/pppp1ppp/2n5/4p3/4P3/5N2/PPPP1PPP/RNBQKB1R b KQkq - 0 1', -400, Plan::Queenside, 2400],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // Only the test positions are active (the fixtures' ones, if any, are deactivated).
        $this->connection()->executeStatement('UPDATE evaluation_position SET active = 0');
        $now = new \DateTimeImmutable();
        foreach (self::POSITIONS as $source => [$fen, $evalCp, $plan, $rating]) {
            $turn = 'w' === explode(' ', $fen)[1] ? Color::White : Color::Black;
            $this->entityManager->persist(new Position($fen, $turn, $evalCp, $plan, ['Idée un.', 'Idée deux.'], 'Un conseil.', PositionTag::Middlegame, $rating, $source, $now));
        }
        $this->entityManager->flush();
    }

    public function testNoCorrectionBeforeTheAnswerThenVerdictsXpAndTheRunCloses(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        // White to move: the three positions have a plan.
        $run = $this->startOk($alice, ['side' => 'white'] + self::CONFIG);

        $first = $this->item($alice, $run);
        self::assertSame(['evaluation_position', 1, 3, 60, 5, true], [$first['type'], $first['data']['index'], $first['data']['count'], $first['data']['seconds'], \count($first['data']['plans']), $first['data']['askPlan']]);
        foreach (['evalCp', 'engine', 'category', 'plan', 'ideas', 'source'] as $secret) {
            self::assertArrayNotHasKey($secret, $first['data'], 'The correction only comes with the verdict.');
            self::assertArrayNotHasKey($secret, $first['data']['position']);
        }
        self::assertSame($first['id'], $this->item($alice, $run)['id'], 'A reload gets the same position.');

        // Exact, the right plan, in a sixth of the time: everything.
        $position = $this->position($first);
        $category = EvaluationRules::category($position->getEvalCp());
        $this->travel('+10 seconds');
        $exact = $this->submitOk($alice, $run, $first['id'], ['guess' => $category, 'plan' => $position->getPlan()?->value], XpRules::EVALUATION_EXACT + XpRules::EVALUATION_PLAN + XpRules::EVALUATION_FAST);
        self::assertSame([true, 'exact', $category, true, true, 10_000], [$exact['success'], $exact['data']['status'], $exact['data']['category'], $exact['data']['planOk'], $exact['data']['fast'], $exact['data']['durationMs']]);
        self::assertSame(EvaluationRules::label($position->getEvalCp()), $exact['data']['engine']);
        self::assertSame($position->getIdeas(), $exact['data']['ideas']);
        self::assertSame(409, $this->submit($alice, $run, $first['id'], ['guess' => $category])->getStatusCode(), 'Already answered.');

        // One category away, the wrong plan.
        $second = $this->item($alice, $run);
        $position = $this->position($second);
        $category = EvaluationRules::category($position->getEvalCp());
        $wrongPlan = Plan::KingAttack === $position->getPlan() ? 'queenside' : 'king_attack';
        $this->travel('+40 seconds');
        $close = $this->submitOk($alice, $run, $second['id'], ['guess' => $category > -2 ? $category - 1 : -1, 'plan' => $wrongPlan], XpRules::EVALUATION_CLOSE);
        self::assertSame([false, 'close', false, $wrongPlan, $position->getPlan()?->value], [$close['success'], $close['data']['status'], $close['data']['planOk'], $close['data']['chosenPlan'], $close['data']['plan'] ?? null]);

        // Past the position's time (and the 2 s of network): time up, whatever the answer.
        $third = $this->item($alice, $run);
        $category = EvaluationRules::category($this->position($third)->getEvalCp());
        $this->travel('+63 seconds');
        $late = $this->submit($alice, $run, $third['id'], ['guess' => $category]);
        self::assertSame(200, $late->getStatusCode(), (string) $late->getContent());
        $step = $this->json($late);
        self::assertSame(XpRules::EVALUATION_MISS, $step['xp'] ?? null);
        self::assertIsArray($step['result'] ?? null);
        self::assertIsArray($step['result']['data'] ?? null);
        self::assertSame('timeout', $step['result']['data']['status'] ?? null);
        self::assertArrayHasKey('guess', $step['result']['data']);
        self::assertNull($step['result']['data']['guess'], 'The late answer is ignored.');

        // Three positions asked, three evaluated: the run is over.
        $closed = $this->getRun($alice, $run);
        self::assertSame(['subject_finished', 3, 1], [$closed['closeReason'], $closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null]);
        $metrics = $closed['summary']['metrics'] ?? null;
        self::assertIsArray($metrics);
        self::assertSame([1, 1, 0, 1, 1], [$metrics['exact'] ?? null, $metrics['close'] ?? null, $metrics['miss'] ?? null, $metrics['timeout'] ?? null, $metrics['planOk'] ?? null]);
        self::assertSame(3, \count(array_unique([$first['data']['position']['fen'], $second['data']['position']['fen'], $third['data']['position']['fen']])), 'Never twice in a run.');

        $review = $this->api('GET', '/api/training/runs/'.$run.'/review', $alice);
        self::assertSame(200, $review->getStatusCode(), (string) $review->getContent());
        $items = $this->json($review)['items'] ?? null;
        self::assertIsArray($items);
        self::assertSame(['ok', 'hint', 'fail'], array_column($items, 'status'));

        $overview = $this->json($this->api('GET', '/api/evaluation', $alice));
        self::assertSame(['white' => 3, 'black' => 3], $overview['positions'] ?? null, 'the test positions only');
        self::assertSame(['played' => 3, 'exact' => 1, 'close' => 1, 'miss' => 0, 'timeout' => 1, 'planOk' => 1], $overview['results'] ?? null);
        $rules = $overview['rules'] ?? null;
        self::assertIsArray($rules);
        self::assertSame(EvaluationRules::SECONDS, $rules['seconds'] ?? null);
    }

    public function testAPositionLeftPastItsTimeIsJudgedWhenTheNextIsAsked(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, self::CONFIG);
        $first = $this->item($alice, $run);
        $this->travel('+70 seconds');

        $second = $this->item($alice, $run);
        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(2, $second['data']['index']);
        self::assertSame('timeout', $this->connection()->fetchOne('SELECT status FROM evaluation_attempt WHERE id = UNHEX(REPLACE(?, \'-\', \'\'))', [$first['id']]));

        // Stopped while the second is on screen and still on time: not counted.
        $this->api('POST', '/api/training/runs/'.$run.'/stop', $alice);
        $closed = $this->getRun($alice, $run);
        self::assertSame(['stopped', 1], [$closed['closeReason'], $closed['summary']['itemCount'] ?? null]);
    }

    public function testPositionsFollowTheSideAndTheEloThenTheCatalogueRunsOut(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        // White to move, Elo 1000: the two positions rated 1000 first, then Lucena (1400).
        $run = $this->startOk($alice, ['count' => 3, 'seconds' => 30, 'elo' => 1000, 'side' => 'white']);
        $keys = [];
        for ($i = 0; $i < 3; ++$i) {
            $item = $this->item($alice, $run);
            self::assertSame('white', $item['data']['position']['turn']);
            $keys[] = $this->position($item)->getSource();
            // One second apart: "seen longest ago" is then well defined.
            $this->travel('+1 second');
            $this->submitOk($alice, $run, $item['id'], ['guess' => 0]);
        }
        $firstTwo = \array_slice($keys, 0, 2);
        sort($firstTwo);
        self::assertSame([['king-pawn-draw', 'start'], 'lucena'], [$firstTwo, $keys[2]]);

        // Four positions with Black to move: there are three.
        $run = $this->startOk($alice, ['count' => 4, 'seconds' => 30, 'elo' => 2000, 'side' => 'black']);
        for ($i = 0; $i < 3; ++$i) {
            $item = $this->item($alice, $run);
            self::assertSame('black', $item['data']['position']['turn']);
            $plan = $this->position($item)->getPlan();
            self::assertSame(null !== $plan, $item['data']['askPlan'], 'The plan is asked only when the position has one.');
            $answered = $this->submitOk($alice, $run, $item['id'], ['guess' => 0, 'plan' => 'simplify']);
            self::assertSame(null === $plan ? null : Plan::Simplify === $plan, $answered['data']['planOk']);
        }
        $response = $this->api('POST', '/api/training/runs/'.$run.'/next', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame('subject_unavailable', $this->getRun($alice, $run)['closeReason']);

        // A new run starts with the positions seen longest ago.
        $run = $this->startOk($alice, ['count' => 3, 'seconds' => 30, 'elo' => 1000, 'side' => 'white']);
        self::assertSame($keys[0], $this->position($this->item($alice, $run))->getSource());
    }

    public function testSettingsAndAnswersAreChecked(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        foreach ([
            ['count' => 2] + self::CONFIG,
            ['count' => 21] + self::CONFIG,
            ['seconds' => 50] + self::CONFIG,
            ['elo' => 700] + self::CONFIG,
            ['side' => 'red'] + self::CONFIG,
            ['level' => 'easy'] + self::CONFIG,
        ] as $config) {
            self::assertSame(422, $this->start($alice, $config)->getStatusCode(), (string) json_encode($config));
        }
        // 3 positions of 60 s do not fit in 2 minutes.
        self::assertSame(422, $this->start($alice, self::CONFIG, 120)->getStatusCode());

        $run = $this->startOk($alice, self::CONFIG);
        $item = $this->item($alice, $run);
        self::assertSame(400, $this->api('POST', '/api/training/runs/'.$run.'/submission', $alice, ['itemId' => $item['id']])->getStatusCode(), 'No evaluation.');
        self::assertSame(422, $this->submit($alice, $run, $item['id'], ['guess' => 3])->getStatusCode());
        self::assertSame(422, $this->submit($alice, $run, $item['id'], ['guess' => 1, 'plan' => 'castle'])->getStatusCode());
        self::assertSame(422, $this->submit($alice, $run, $item['id'], ['guess' => 1, 'cheat' => true])->getStatusCode());
        // No answer in time (sent by the client at zero): time up.
        self::assertSame('timeout', $this->submitOk($alice, $run, $item['id'], ['guess' => null])['data']['status']);
    }

    /**
     * @param EvalItem $item
     */
    private function position(array $item): Position
    {
        $this->entityManager->clear();
        $position = $this->entityManager->getRepository(Position::class)->findOneBy(['fen' => $item['data']['position']['fen']]);
        self::assertInstanceOf(Position::class, $position);

        return $position;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function start(User $user, array $config, int $budgetSeconds = 600): Response
    {
        return $this->api('POST', '/api/training/runs', $user, ['module' => 'evaluation', 'subjectId' => $user->getId()->toRfc4122(), 'budgetSeconds' => $budgetSeconds, 'config' => $config]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function startOk(User $user, array $config): string
    {
        $response = $this->start($user, $config);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $id = $this->json($response)['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return EvalItem
     */
    private function item(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $item = $this->json($response)['item'] ?? null;
        self::assertIsArray($item, 'The run served no position.');

        /** @var EvalItem */
        return $item;
    }

    /**
     * @param array<string, mixed> $evaluation
     */
    private function submit(User $user, string $runId, string $itemId, array $evaluation): Response
    {
        return $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId, 'evaluation' => $evaluation]);
    }

    /**
     * @param array<string, mixed> $evaluation
     * @param int|null             $xp         the XP the response must announce, if checked
     *
     * @return array{itemId: string, success: bool, data: array<string, mixed>}
     */
    private function submitOk(User $user, string $runId, string $itemId, array $evaluation, ?int $xp = null): array
    {
        $response = $this->submit($user, $runId, $itemId, $evaluation);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $step = $this->json($response);
        if (null !== $xp) {
            self::assertSame($xp, $step['xp'] ?? null);
        }
        $result = $step['result'] ?? null;
        self::assertIsArray($result);

        /** @var array{itemId: string, success: bool, data: array<string, mixed>} */
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

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
