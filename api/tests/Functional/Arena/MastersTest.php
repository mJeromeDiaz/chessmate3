<?php

declare(strict_types=1);

namespace App\Tests\Functional\Arena;

use App\Chess\Position\PositionKey;
use App\Chess\Rules;
use App\Entity\Repertoire\Move;
use App\Entity\Repertoire\Position;
use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\MindMap\Capacity;
use App\Enum\MindMap\StepKind;
use App\Enum\Repertoire\Color;
use App\MindMap\Path\Paths;
use App\Repertoire\Graph\GraphEditor;
use App\Repertoire\RepertoireManager;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * The professors of the arena (docs/ARENA.md, § 2 bis): each one arrives with a feat in their module
 * (Lizy: every endgame lesson mastered; Albert: a puzzle streak of 50; Aaron: a repertoire of more
 * than 50 lines; Ray: a hard free blindfold run validated), the last level, never sold, and their
 * exceptional badges: the feat, then the professor beaten.
 *
 * @phpstan-type FeatBoss array{character: string, tier: string, level: int, price: int|null, elo: int, openingElo: int|null, endgameElo: int|null, guarded: bool, status: string, crystals: int, feat: array{current: int, goal: int}|null}
 * @phpstan-type TrophyJson array{key: string, goal: int, current: int, unlocked: bool, unlockedAt: string|null}
 */
final class MastersTest extends WoodpeckerWebTestCase
{
    private const SCHOLAR = ['e2e4', 'e7e5', 'f1c4', 'b8c6', 'd1h5', 'g8f6', 'h5f7'];

    private int $sequence = 0;

    public function testTheProfessorsAreTheLastLevelLockedUntilTheirFeatAndNeverSold(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $lizy = $this->boss($alice, 'lizy');
        self::assertSame(['master', 6, null, 2500, null, 2700, true, 'locked', 1000], [
            $lizy['tier'], $lizy['level'], $lizy['price'], $lizy['elo'], $lizy['openingElo'], $lizy['endgameElo'], $lizy['guarded'], $lizy['status'], $lizy['crystals'],
        ]);
        self::assertSame(['current' => 0, 'goal' => \count($this->lessons())], $lizy['feat']);
        self::assertSame([2600, 2650, 2850, 2800], [$this->boss($alice, 'albert')['elo'], $this->boss($alice, 'aaron')['elo'], $this->boss($alice, 'aaron')['openingElo'], $this->boss($alice, 'ray')['elo']]);
        self::assertSame(['current' => 0, 'goal' => 1], $this->boss($alice, 'ray')['feat']);
        self::assertNull($this->boss($alice, 'zombie')['feat'], 'only the professors have a feat');
        $this->assertRefused($this->api('POST', '/api/arena/games', $alice, ['boss' => 'albert']), 409, 'arena_boss_locked');
        $this->assertRefused($this->api('POST', '/api/arena/bosses/albert/purchase', $alice), 409, 'arena_boss_not_for_sale');
        $trophies = $this->trophies($alice);
        self::assertSame([false, 0, \count($this->lessons())], [$trophies['master_lizy']['unlocked'], $trophies['master_lizy']['current'], $trophies['master_lizy']['goal']]);
        self::assertSame([false, 0, 1], [$trophies['master_ray_beaten']['unlocked'], $trophies['master_ray_beaten']['current'], $trophies['master_ray_beaten']['goal']]);
    }

    public function testLizyArrivesWithTheLastEndgameLessonMastered(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $lessons = $this->lessons();
        $last = array_pop($lessons);
        foreach ($lessons as $i => $lesson) {
            $this->completed($alice, $lesson, \sprintf('2026-09-%02d 09:00:00', 1 + $i % 20));
        }
        $this->connection()->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, 'endgames', ?, 9, NULL, '2026-09-27 09:00:00')",
            [Uuid::v7()->toBinary(), $alice->getId()->toBinary(), $last],
        );
        $lizy = $this->boss($alice, 'lizy');
        self::assertSame(['locked', \count($lessons)], [$lizy['status'], $lizy['feat']['current'] ?? null], 'nine passes: known, not mastered');

        $this->connection()->executeStatement("UPDATE mind_map_progress SET passes = 10, completed_at = '2026-09-27 18:30:00' WHERE step = ?", [$last]);
        self::assertSame('available', $this->boss($alice, 'lizy')['status']);
        self::assertContains('lizy', $this->pending($alice));
        $trophies = $this->trophies($alice);
        self::assertSame([true, '2026-09-27T18:30:00+00:00'], [$trophies['master_lizy']['unlocked'], $trophies['master_lizy']['unlockedAt']], 'the last lesson\'s mastery');
        self::assertFalse($trophies['master_albert']['unlocked']);
    }

    public function testAlbertArrivesWithTheFiftiethPuzzleOfAStreak(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->puzzleStreak($alice, '2026-09-20 08:00:00', 49);
        self::assertSame(['locked', ['current' => 49, 'goal' => 50]], [$this->boss($alice, 'albert')['status'], $this->boss($alice, 'albert')['feat']]);

        $this->puzzleStreak($alice, '2026-09-21 08:00:00', 52);
        self::assertSame('available', $this->boss($alice, 'albert')['status']);
        $trophies = $this->trophies($alice);
        self::assertSame([true, '2026-09-21T08:50:00+00:00'], [$trophies['master_albert']['unlocked'], $trophies['master_albert']['unlockedAt']], 'the 50th puzzle solved');
        self::assertTrue($trophies['streak_puzzles_50']['unlocked'], 'with « Imparable »');
    }

    public function testAaronArrivesWithARepertoireOfMoreThanFiftyLinesAndStays(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $small = $this->repertoire($alice, 'Petit');
        $big = $this->repertoire($alice, 'Grand');
        $lines = $this->lineSans();
        foreach (\array_slice($lines, 0, 30) as $sans) {
            $this->addLine($small, $sans);
        }
        foreach (\array_slice($lines, 0, 50) as $sans) {
            $this->addLine($big, $sans);
        }
        self::assertSame(['locked', ['current' => 50, 'goal' => 51]], [$this->boss($alice, 'aaron')['status'], $this->boss($alice, 'aaron')['feat']], 'one repertoire counts, not their sum');

        $this->addLine($big, $lines[50]);
        self::assertSame('available', $this->boss($alice, 'aaron')['status']);
        self::assertContains('aaron', $this->pending($alice));
        $this->travel('+3 days');
        $this->connection()->executeStatement('DELETE FROM repertoire_move WHERE repertoire_id = ? ORDER BY id DESC LIMIT 2', [$big->getId()->toBinary()]);
        self::assertSame('available', $this->boss($alice, 'aaron')['status'], 'arrived, he stays');
        $trophies = $this->trophies($alice);
        self::assertSame([true, '2026-09-28T10:00:00+00:00'], [$trophies['master_aaron']['unlocked'], $trophies['master_aaron']['unlockedAt']], 'the day he arrived');
    }

    public function testRayArrivesWithAHardFreeBlindfoldRunValidated(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $hard = ['level' => 'hard', 'length' => 4, 'visibleSeconds' => 10];
        // Each one misses one condition.
        $this->blindfoldRun($alice, '2026-09-01 10:00:00', ['visibleSeconds' => 15] + $hard, 1_200, [5, 0, 0]);
        $this->blindfoldRun($alice, '2026-09-02 10:00:00', ['length' => 3] + $hard, 1_200, [5, 0, 0]);
        $this->blindfoldRun($alice, '2026-09-03 10:00:00', ['level' => 'medium'] + $hard, 1_200, [5, 0, 0]);
        $this->blindfoldRun($alice, '2026-09-04 10:00:00', $hard, 1_140, [5, 0, 0]);
        $this->blindfoldRun($alice, '2026-09-05 10:00:00', $hard, 1_200, [5, 0, 0], 'stopped');
        $this->blindfoldRun($alice, '2026-09-06 10:00:00', $hard, 1_200, [5, 0, 0], parent: true);
        $this->blindfoldRun($alice, '2026-09-07 10:00:00', $hard, 1_200, [5, 0, 0], pathStep: 'visualisation:1-1-1');
        $this->blindfoldRun($alice, '2026-09-08 10:00:00', $hard, 1_200, [4, 0, 0]);
        $this->blindfoldRun($alice, '2026-09-09 10:00:00', $hard, 1_200, [7, 1, 1]);
        self::assertSame(['locked', ['current' => 0, 'goal' => 1]], [$this->boss($alice, 'ray')['status'], $this->boss($alice, 'ray')['feat']], '7 of 9: 78 %');

        $this->blindfoldRun($alice, '2026-09-10 10:00:00', ['visibleSeconds' => 5] + $hard, 1_800, [4, 1, 0]);
        self::assertSame('available', $this->boss($alice, 'ray')['status'], '4 of 5 without a peek: 80 %');
        $trophies = $this->trophies($alice);
        self::assertSame([true, '2026-09-10T10:30:00+00:00'], [$trophies['master_ray']['unlocked'], $trophies['master_ray']['unlockedAt']]);
    }

    public function testBeatingAProfessorPaysAThousandCrystalsAndItsBadge(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->puzzleStreak($alice, '2026-09-21 08:00:00', 50);
        $game = $this->json($this->api('POST', '/api/arena/games', $alice, ['boss' => 'albert', 'color' => 'white', 'cadence' => '5+3']));
        $id = $game['id'] ?? null;
        self::assertIsString($id);
        self::assertSame(2600, $game['elo'] ?? null);
        self::assertArrayHasKey('openingElo', $game);
        self::assertNull($game['openingElo'], 'Albert: one strength all game long');
        $won = [];
        foreach (self::SCHOLAR as $ply => $move) {
            $response = $this->api('POST', '/api/arena/games/'.$id.'/moves', $alice, ['ply' => $ply, 'move' => $move]);
            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $won = $this->json($response);
        }
        self::assertSame(['won', 1000], [$won['status'] ?? null, $won['crystals'] ?? null]);
        $trophies = $this->trophies($alice);
        self::assertSame([true, '2026-09-28T10:00:00+00:00'], [$trophies['master_albert_beaten']['unlocked'], $trophies['master_albert_beaten']['unlockedAt']]);
        self::assertFalse($trophies['master_lizy_beaten']['unlocked']);
    }

    /**
     * @return FeatBoss
     */
    private function boss(User $user, string $character): array
    {
        $response = $this->api('GET', '/api/arena', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $bosses = $this->json($response)['bosses'] ?? [];
        foreach (\is_array($bosses) ? $bosses : [] as $boss) {
            if (\is_array($boss) && $character === ($boss['character'] ?? null)) {
                /** @var FeatBoss */
                return $boss;
            }
        }
        self::fail($character.' is not in the arena');
    }

    /**
     * @return list<string>
     */
    private function pending(User $user): array
    {
        $response = $this->api('GET', '/api/arena/unlocks', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $bosses = $this->json($response)['bosses'] ?? [];

        return array_values(array_map(static fn (mixed $boss): string => \is_array($boss) && \is_string($boss['character'] ?? null) ? $boss['character'] : '', \is_array($bosses) ? $bosses : []));
    }

    /**
     * @return array<string, TrophyJson>
     */
    private function trophies(User $user): array
    {
        $response = $this->api('GET', '/api/gamification/trophies', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var list<TrophyJson> $trophies */
        $trophies = $this->json($response)['trophies'] ?? [];

        return array_column($trophies, null, 'key');
    }

    private function assertRefused(\Symfony\Component\HttpFoundation\Response $response, int $status, string $reason): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString($reason, (string) $response->getContent());
    }

    /**
     * The lessons of the endgames path's mastery chapters.
     *
     * @return list<string>
     */
    private function lessons(): array
    {
        $lessons = [];
        foreach (Paths::chapters(Capacity::Endgames) as $chapter) {
            foreach ($chapter->mastery ? $chapter->allSteps() : [] as $step) {
                if (StepKind::Exercise === $step->kind) {
                    $lessons[] = $step->key;
                }
            }
        }

        return $lessons;
    }

    private function completed(User $user, string $step, string $at): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, 'endgames', ?, 10, ?, ?)",
            [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $step, $at, $at],
        );
    }

    /**
     * A puzzle streak over: $length puzzles solved a minute apart from $start, then a miss.
     */
    private function puzzleStreak(User $user, string $start, int $length): void
    {
        $connection = $this->connection();
        $userId = $user->getId()->toBinary();
        $run = $this->closedRun($user, 'puzzle_streak', [], 3_600, $start, 'time_up');
        $streak = Uuid::v7()->toBinary();
        $at = new \DateTimeImmutable($start);
        $connection->executeStatement(
            "INSERT INTO puzzle_streak (id, ramp, topic, length, status, record, started_at, ended_at, user_id, run_id) VALUES (?, 'ladder', 'general', ?, 'broken', 0, ?, ?, ?, ?)",
            [$streak, $length, $at->format('Y-m-d H:i:s'), $at->modify(\sprintf('+%d minutes', $length + 1))->format('Y-m-d H:i:s'), $userId, $run],
        );
        for ($position = 0; $position <= $length; ++$position) {
            $connection->executeStatement(
                'INSERT INTO puzzle_streak_attempt (id, puzzle_id, puzzle_rating, position, target_rating, status, started_at, submitted_at, user_id, run_id, streak_id)
                 VALUES (?, ?, 1000, ?, 1000, ?, ?, ?, ?, ?, ?)',
                [Uuid::v7()->toBinary(), ++$this->sequence, $position, $position < $length ? 'solved' : 'failed', $at->format('Y-m-d H:i:s'),
                    $at->modify(\sprintf('+%d minutes', $position + 1))->format('Y-m-d H:i:s'), $userId, $run, $streak],
            );
        }
    }

    /**
     * A blindfold run closed $budget seconds after $start, its puzzles [solved, helped, failed].
     *
     * @param array<string, mixed>            $config
     * @param array{0: int, 1: int, 2: int}   $counts
     */
    private function blindfoldRun(User $user, string $start, array $config, int $budget, array $counts, string $reason = 'time_up', bool $parent = false, ?string $pathStep = null): void
    {
        $run = $this->closedRun($user, 'blindfold', $config, $budget, $start, $reason, $parent ? Uuid::v7()->toBinary() : null, $pathStep);
        $at = (new \DateTimeImmutable($start))->format('Y-m-d H:i:s');
        foreach (['solved', 'helped', 'failed'] as $i => $status) {
            for ($n = 0; $n < $counts[$i]; ++$n) {
                $this->connection()->executeStatement(
                    'INSERT INTO blindfold_puzzle_attempt (id, user_id, run_id, puzzle_id, level, length, visible_seconds, status, started_at, submitted_at, duration_ms, mistakes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 60000, 0)',
                    [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $run, ++$this->sequence, $config['level'], $config['length'], $config['visibleSeconds'], $status, $at, $at],
                );
            }
        }
        // The puzzle on screen at the end: never counted.
        $this->connection()->executeStatement(
            "INSERT INTO blindfold_puzzle_attempt (id, user_id, run_id, puzzle_id, level, length, visible_seconds, status, started_at, mistakes) VALUES (?, ?, ?, ?, ?, ?, ?, 'pending', ?, 0)",
            [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $run, ++$this->sequence, $config['level'], $config['length'], $config['visibleSeconds'], $at],
        );
    }

    /**
     * A closed timed run.
     *
     * @param array<string, mixed> $config
     *
     * @return string its id, binary
     */
    private function closedRun(User $user, string $module, array $config, int $budget, string $start, string $reason, ?string $parent = null, ?string $pathStep = null): string
    {
        $id = Uuid::v7()->toBinary();
        $started = new \DateTimeImmutable($start);
        $closed = $started->modify(\sprintf('+%d seconds', $budget))->format('Y-m-d H:i:s');
        $this->connection()->executeStatement(
            "INSERT INTO training_run (id, user_id, module, subject_type, subject_id, config, budget_seconds, status, started_at, expires_at, closed_at, close_reason, parent_id, path_step)
             VALUES (?, ?, ?, 'user', ?, ?, ?, 'closed', ?, ?, ?, ?, ?, ?)",
            [$id, $user->getId()->toBinary(), $module, $user->getId()->toBinary(), json_encode((object) $config, \JSON_THROW_ON_ERROR), $budget, $started->format('Y-m-d H:i:s'), $closed, $closed, $reason, $parent, $pathStep],
        );

        return $id;
    }

    private function repertoire(User $user, string $name): Repertoire
    {
        return self::getContainer()->get(RepertoireManager::class)->create($user, $name, Color::Black);
    }

    /**
     * 51 lines of a black repertoire, each a line end: 1.<white> Nc6 2.<white>, unanswered.
     *
     * @return list<string>
     */
    private function lineSans(): array
    {
        $firsts = ['a3', 'b3', 'c3', 'd3', 'e3', 'f3', 'g3', 'h3', 'a4', 'b4', 'c4', 'd4', 'e4', 'f4', 'g4', 'h4', 'Nf3'];
        // A second move and the first moves that make it illegal.
        $seconds = ['Nh3' => ['h3', 'Nf3'], 'Na3' => ['a3'], 'Nc3' => ['c3'], 'Nf3' => ['f3', 'Nf3'], 'a3' => ['a3', 'a4'], 'h3' => ['h3', 'h4']];
        $lines = [];
        foreach ($firsts as $first) {
            $legal = array_keys(array_filter($seconds, static fn (array $illegalAfter): bool => !\in_array($first, $illegalAfter, true)));
            foreach (\array_slice($legal, 0, 3) as $second) {
                $lines[] = $first.' Nc6 '.$second;
            }
        }
        self::assertCount(51, $lines);

        return $lines;
    }

    /** Plays SAN moves from the initial position, adding the missing ones. */
    private function addLine(Repertoire $repertoire, string $sans): void
    {
        $em = $this->entityManager;
        $hash = PositionKey::of(Rules::initial()->normalizedFen())->hash;
        $position = $em->getRepository(Position::class)->findOneBy(['repertoire' => $repertoire, 'fenHash' => $hash]) ?? throw new \LogicException('No root.');
        foreach (explode(' ', $sans) as $san) {
            $move = Rules::fromFen($position->getFen())->playSan($san) ?? throw new \LogicException('Illegal '.$san);
            $change = self::getContainer()->get(GraphEditor::class)->addMove($repertoire->getUser(), $repertoire->getId(), $position->getId(), Rules::uci($move));
            $position = ($em->find(Move::class, Uuid::fromString((string) $change->moveId)) ?? throw new \LogicException('No move.'))->getTo();
        }
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
