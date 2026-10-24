<?php

declare(strict_types=1);

namespace App\Tests\Functional\Endgame;

use App\Endgame\EndgameRules;
use App\Entity\User;
use App\MindMap\Path\Endgames;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Symfony\Component\Uid\Uuid;

/**
 * The endgames path (docs/ENDGAMES.md, § 10): nothing locked, a lesson's pass of 5 positions
 * (learn first, then review) at the Elo proposed to the player, ended by its count; a boss's
 * fight ended by its lives; the Roi des cendres' clock per position; the mastery badge; the
 * path's options refused outside it.
 *
 * @phpstan-type EndgameItem array{id: string, type: string, data: array<string, mixed>}
 */
final class EndgamePathTest extends WoodpeckerWebTestCase
{
    use KeptAnswersTrait;

    private const START = '8/8/8/P7/8/8/5k2/7K w - - 0 1';
    private const GAME = ['a5a6', 'f2e3', 'a6a7', 'e3d4', 'a7a8q'];
    /** The Queen's mate, the only mate the Roi des cendres can serve here (its length kept). */
    private const KQK = '8/8/8/4k3/8/8/8/3QK3 w - - 0 1';

    protected function setUp(): void
    {
        parent::setUp();
        self::keep(self::START, self::winWith('a5a6', 'h1h2', 23));
        self::keep('8/8/P7/8/8/4k3/8/7K w - - 0 1', self::winWith('a6a7'));
        self::keep('8/P7/8/8/3k4/8/8/7K w - - 0 1', self::winWith('a7a8q'));
    }

    public function testThePathAtTheStartLocksNothing(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $path = $this->path($alice);

        self::assertSame('endgames', $path['capacity']);
        self::assertSame(['mates', 'pawns-basics', 'pawns-technique', 'queens', 'rooks', 'bishops', 'knights', 'ashes'], array_column($path['chapters'], 'key'));
        self::assertSame(['open'], array_values(array_unique(array_column($path['chapters'], 'status'))));
        $steps = $path['chapters'][4]['sections'][0]['steps'];
        self::assertSame(['king-cut-off', 'lucena', 'philidor', 'rook-vs-pawn', 'vancura'], array_column($steps, 'key'));
        self::assertSame(['open', 0, 10, 0, 15], [$steps[1]['status'], $steps[1]['passes'], $steps[1]['required'], $steps[1]['crystals'], $steps[1]['xp']]);
        $boss = $path['chapters'][4]['boss'];
        self::assertSame(['rooks-boss', 'boss', 'open', 'maitre-tours', 180, 60], [$boss['key'], $boss['kind'], $boss['status'], $boss['character'], $boss['crystals'], $boss['xp']]);
        self::assertSame(['ashes-boss', 'open', 'roi-cendres', 600], [$path['chapters'][7]['boss']['key'], $path['chapters'][7]['boss']['status'], $path['chapters'][7]['boss']['character'], $path['chapters'][7]['boss']['crystals']]);
        self::assertSame([], $path['chapters'][7]['sections']);
    }

    public function testALessonIsLearntThenReviewed(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startStep($alice, 'square-promote');
        self::assertSame('endgames', $run['module']);
        self::assertEquals(['mode' => 'learn', 'variants' => 4, 'lessons' => ['square-promote'], 'count' => 5], $this->configOf($run['id']));

        // No variant in the pool: the textbook position each time, explained the first time only.
        for ($i = 1; $i <= 5; ++$i) {
            $item = $this->item($alice, $run['id']);
            self::assertSame(
                [self::START, 1 === $i, $i, 5, null, ['elo' => 1500, 'perfect' => false]],
                [$item['data']['fen'], $item['data']['explain'], $item['data']['index'], $item['data']['count'], $item['data']['lives'], $item['data']['opponent']],
                'Maia at the Elo proposed to a player without rating.',
            );
            $result = $this->submit($alice, $run['id'], ['itemId' => $item['id'], 'moves' => self::GAME]);
            self::assertSame(['won', $i, 5], [$result['data']['status'], $result['data']['position'], $result['data']['count']]);
        }

        $closed = $this->json($this->api('GET', '/api/training/runs/'.$run['id'], $alice));
        self::assertSame(['closed', 'subject_finished', 'count_done'], [$closed['status'] ?? null, $closed['closeReason'] ?? null, $this->reasonOf($closed)]);
        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$run['id'], $alice));
        self::assertSame(['passed', 1, 10, false], [$outcome['outcome'] ?? null, $outcome['passes'] ?? null, $outcome['required'] ?? null, $outcome['completed'] ?? null]);

        // Its next passes: review mode, without help.
        $again = $this->startStep($alice, 'square-promote');
        self::assertEquals(['mode' => 'review', 'lessons' => ['square-promote'], 'count' => 5], $this->configOf($again['id']));
        self::assertSame('review', $this->item($alice, $again['id'])['data']['mode']);
    }

    public function testABossIsLostAtItsThirdMissedPosition(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startStep($alice, 'pawns-basics-boss');
        self::assertEquals(['mode' => 'review', 'lessons' => ['square-promote', 'square-stop', 'opposition', 'key-squares'], 'elo' => 2600, 'count' => 6, 'lives' => 3], $this->configOf($run['id']));

        for ($lives = 2; $lives >= 0; --$lives) {
            $item = $this->item($alice, $run['id']);
            self::assertSame([['elo' => null, 'perfect' => true], 'review'], [$item['data']['opponent'], $item['data']['mode']]);
            $result = $this->submit($alice, $run['id'], ['itemId' => $item['id'], 'moves' => [], 'pass' => true]);
            self::assertSame(['resigned', $lives], [$result['data']['status'], $result['data']['lives']]);
        }

        $closed = $this->json($this->api('GET', '/api/training/runs/'.$run['id'], $alice));
        self::assertSame(['closed', 'lives_out'], [$closed['status'] ?? null, $this->reasonOf($closed)]);
        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$run['id'], $alice));
        self::assertSame(['failed', 3, 0], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null]);
    }

    public function testTheRoiDesCendresServesAShortMateFirstOnItsClock(): void
    {
        // The other mates' lengths are unknown (no kept answer): they are not served.
        self::keep(self::KQK, self::winWith('d1d4', null, 19));
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startStep($alice, 'ashes-boss');
        $config = $this->configOf($run['id']);
        self::assertSame(['review', 2600, 10, 3], [$config['mode'] ?? null, $config['elo'] ?? null, $config['count'] ?? null, $config['lives'] ?? null]);
        self::assertCount(7, \is_array($config['ashes'] ?? null) ? $config['ashes'] : [], 'The 7 mini-parcours.');

        $item = $this->item($alice, $run['id']);
        $data = $item['data'];
        self::assertSame(
            ['kqk', self::KQK, 'review', ['elo' => null, 'perfect' => true], 1, 10, 3, 90, 1, 13],
            [$data['lesson'], $data['fen'], $data['mode'], $data['opponent'], $data['index'], $data['count'], $data['lives'], $data['clockSeconds'], $data['tier'], $data['budgetMoves']],
            'Mate in 10 moves: +25 %, 13 moves allowed.',
        );
        self::assertIsString($data['servedAt']);
        self::assertIsString($data['deadline']);
        self::assertSame(90, (new \DateTimeImmutable($data['deadline']))->getTimestamp() - (new \DateTimeImmutable($data['servedAt']))->getTimestamp());

        // In time: judged as usual.
        $result = $this->submit($alice, $run['id'], ['itemId' => $item['id'], 'moves' => [], 'pass' => true]);
        self::assertSame(['resigned', 2, 1], [$result['data']['status'], $result['data']['lives'], $result['data']['position']]);
        $next = $this->item($alice, $run['id'])['data'];
        self::assertSame([2, 82, 1], [$next['index'], $next['clockSeconds'], $next['tier']]);
        self::assertNotContains($next['lesson'], ['kqk', 'krk', 'kbbk', 'kbnk'], 'Played, or of an unknown length.');
    }

    public function testALateSubmissionIsATimeout(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startStep($alice, 'ashes-boss');
        $item = $this->item($alice, $run['id']);
        self::assertNotSame('kqk', $item['data']['lesson'], 'No mate of a known length: another lesson.');

        $this->backdate($run['id'], EndgameRules::ashesClock(1) + EndgameRules::ASHES_TOLERANCE_SECONDS + 1);
        // The game is checked before (an illegal move: 400); none played here.
        $result = $this->submit($alice, $run['id'], ['itemId' => $item['id'], 'moves' => [], 'pass' => true]);

        self::assertFalse($result['success']);
        self::assertSame(['timeout', [], 2], [$result['data']['status'], $result['data']['moves'], $result['data']['lives']], 'Beyond the 2 s of tolerance.');
    }

    public function testThePositionsClockRunsOutWhileAwayAndTheThirdEndsTheFight(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startStep($alice, 'ashes-boss');

        for ($index = 1; $index <= 3; ++$index) {
            $item = $this->item($alice, $run['id']);
            self::assertSame([$index, 4 - $index], [$item['data']['index'], $item['data']['lives']]);
            // A reload in time: the same position, its clock running.
            self::assertSame($item['id'], $this->item($alice, $run['id'])['id']);
            $this->backdate($run['id'], EndgameRules::ashesClock($index) + 3);
        }
        $response = $this->api('POST', '/api/training/runs/'.$run['id'].'/next', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $step = $this->json($response);
        self::assertNull($step['item'] ?? null);

        $closed = $this->json($this->api('GET', '/api/training/runs/'.$run['id'], $alice));
        self::assertSame(['closed', 'subject_finished', 'lives_out'], [$closed['status'] ?? null, $closed['closeReason'] ?? null, $this->reasonOf($closed)]);
        $outcome = $this->json($this->api('GET', '/api/mind-map/runs/'.$run['id'], $alice));
        self::assertSame(['failed', 3, 0], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null]);
        self::assertSame(['timeout', 'timeout', 'timeout'], self::getContainer()->get(Connection::class)->fetchFirstColumn('SELECT status FROM endgame_attempt WHERE run_id = ? ORDER BY served_at', [Uuid::fromString($run['id'])->toBinary()]));
    }

    public function testEveryLessonMasteredEarnsTheBadge(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $lessons = Endgames::lessonKeys();
        $last = array_pop($lessons);
        self::assertNotNull($last);
        foreach ($lessons as $key) {
            $this->mastered($alice, $key, '2026-09-28 09:00:00');
        }
        self::assertSame([], $this->path($alice)['badges']);

        $this->mastered($alice, $last, '2026-09-29 10:00:00');
        self::assertSame([['badge' => Endgames::MASTERY_BADGE, 'step' => $last, 'earnedAt' => '2026-09-29T10:00:00+00:00']], $this->path($alice)['badges']);
    }

    public function testThePathsOptionsAreRefusedOutsideIt(): void
    {
        $alice = $this->createUserIn('alice@example.com');

        foreach ([
            ['mode' => 'learn', 'lessons' => ['square-promote']],
            ['mode' => 'learn', 'lessons' => ['square-promote'], 'elo' => 1500, 'count' => 5],
            ['mode' => 'learn', 'lessons' => ['square-promote'], 'elo' => 1500, 'lives' => 3],
            ['mode' => 'learn', 'lessons' => ['square-promote'], 'elo' => 1500, 'variants' => 4],
            ['mode' => 'review', 'ashes' => [['square-promote']], 'elo' => 1500],
        ] as $config) {
            $response = $this->api('POST', '/api/training/runs', $alice, ['module' => 'endgames', 'subjectId' => $alice->getId()->toRfc4122(), 'budgetSeconds' => 300, 'config' => $config]);
            self::assertSame(422, $response->getStatusCode(), (string) json_encode($config));
        }
    }

    /**
     * @return array{id: string, module: string}
     */
    private function startStep(User $user, string $key): array
    {
        $response = $this->api('POST', '/api/mind-map/endgames/steps/'.$key.'/start', $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);

        /** @var array{id: string, module: string} */
        return $run;
    }

    /**
     * The run's config as the server stored it (from the step, never from the client); MySQL's
     * JSON reorders its keys, hence assertEquals.
     *
     * @return array<string, mixed>
     */
    private function configOf(string $runId): array
    {
        $config = self::getContainer()->get(Connection::class)->fetchOne('SELECT config FROM training_run WHERE id = ?', [Uuid::fromString($runId)->toBinary()]);
        self::assertIsString($config);
        $decoded = json_decode($config, true, 512, \JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        /** @var array<string, mixed> */
        return $decoded;
    }

    /**
     * Why a closed run ended, from its summary's context.
     *
     * @param array<mixed> $run
     */
    private function reasonOf(array $run): mixed
    {
        $summary = $run['summary'] ?? null;
        self::assertIsArray($summary);
        $context = $summary['context'] ?? null;
        self::assertIsArray($context);

        return $context['reason'] ?? null;
    }

    /**
     * @return EndgameItem
     */
    private function item(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $item = $this->json($response)['item'] ?? null;
        self::assertIsArray($item, 'The run served no position.');

        /** @var EndgameItem */
        return $item;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{itemId: string, success: bool, data: array<string, mixed>}
     */
    private function submit(User $user, string $runId, array $body): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, $body);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var array{itemId: string, success: bool, data: array<string, mixed>} */
        return $result;
    }

    /**
     * @return array{capacity: string, chapters: list<array{key: string, status: string, sections: list<array{steps: list<array<string, mixed>>}>, boss: array<string, mixed>}>, badges: list<array<string, string>>}
     */
    private function path(User $user): array
    {
        $response = $this->api('GET', '/api/mind-map/endgames', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{capacity: string, chapters: list<array{key: string, status: string, sections: list<array{steps: list<array<string, mixed>>}>, boss: array<string, mixed>}>, badges: list<array<string, string>>} */
        return $this->json($response);
    }

    /** The position on screen served $seconds earlier. */
    private function backdate(string $runId, int $seconds): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE endgame_attempt SET served_at = served_at - INTERVAL ? SECOND WHERE run_id = ? AND status = 'pending'",
            [$seconds, Uuid::fromString($runId)->toBinary()],
            [ParameterType::INTEGER, ParameterType::BINARY],
        );
    }

    /** A lesson set as mastered (its 10 passes). */
    private function mastered(User $user, string $step, string $at): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, 'endgames', ?, 10, ?, ?)",
            [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $step, $at, $at],
        );
    }
}
