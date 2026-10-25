<?php

declare(strict_types=1);

namespace App\Tests\Functional\Challenge;

use App\Chess\Position\PositionKey;
use App\Chess\Rules;
use App\Entity\Catalog\Puzzle;
use App\Entity\Repertoire\Position;
use App\Entity\Repertoire\Repertoire;
use App\Entity\User;
use App\Enum\Repertoire\Color;
use App\MindMap\Path\Challenges;
use App\Puzzle\Selection\SelectionRebuilder;
use App\Repertoire\Graph\GraphEditor;
use App\Repertoire\Opening\OpeningSynchronizer;
use App\Repertoire\RepertoireManager;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use PChess\Chess\Chess;
use Symfony\Component\Uid\Uuid;

/**
 * The défis' path (docs/DEFIS.md): three fixed chapters, nothing locked; a pass of 10 puzzles
 * below, at and above the player's rating, at 80 %, never rated; a boss of the chapter's tiers
 * mixed above the rating, ended at its third miss, its crystals paid once; the mastery badge of
 * the fixed chapters; an opening chapter per repertoire, its boss paid once per opening and side;
 * the path's options refused outside it.
 *
 * @phpstan-type PuzzleJson array{id: string, fen: string, moves: list<string>, playerColor: string, rating: int, themes: list<string>, gameUrl: string}
 * @phpstan-type TierItem array{id: string, type: string, data: array{puzzle: PuzzleJson, tier: int|null, themes: list<string>, family: string|null, position: int, count: int, lives: int|null, failures: int, targetRating: int, startedAt: string}}
 * @phpstan-type StepJson array{key: string, kind: string, status: string, passes: int, required: int, crystals: int, xp: int, character: string|null, chest: array{crystals: int, opened: bool}|null}
 * @phpstan-type ChapterJson array{key: string, number: int, status: string, sections: list<array{steps: list<StepJson>}>, boss: StepJson, meta: array<string, mixed>|null}
 * @phpstan-type PathJson array{capacity: string, chapters: list<ChapterJson>, badges: list<array<string, string>>}
 */
final class ChallengePathTest extends WoodpeckerWebTestCase
{
    /** Black to move: the player has White, one move to find. */
    private const WHITE_SOLVES = ['r2q3r/pp1n1pk1/2pbbn1B/3pR3/3P3Q/3B4/PPP2PPP/RN4K1 b - - 9 16', 'g7g8 e5g5'];
    private const MATES = ['mateIn1', 'mateIn2', 'mateIn3', 'mateIn4', 'mateIn5'];

    public function testThePathAtTheStartLocksNothing(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $path = $this->path($alice);

        self::assertSame('challenges', $path['capacity']);
        self::assertSame(['mates', 'endgames', 'tactics'], array_column($path['chapters'], 'key'), 'no opening chapter without a repertoire');
        self::assertSame(['open'], array_values(array_unique(array_column($path['chapters'], 'status'))));
        $steps = $path['chapters'][0]['sections'][0]['steps'];
        self::assertSame(['mates-1', 'mates-2', 'mates-3', 'mates-4', 'mates-5'], array_column($steps, 'key'));
        self::assertSame(['open', 0, 10, 0, 15], [$steps[3]['status'], $steps[3]['passes'], $steps[3]['required'], $steps[3]['crystals'], $steps[3]['xp']]);
        $boss = $path['chapters'][2]['boss'];
        self::assertSame(['tactics-boss', 'boss', 'open', 'defi-tactics', 150, 60], [$boss['key'], $boss['kind'], $boss['status'], $boss['character'], $boss['crystals'], $boss['xp']]);
        self::assertNull($path['chapters'][1]['meta']);
    }

    public function testAPassIsTenPuzzlesBelowAtAndAboveTheRatingAndCountsAt80Percent(): void
    {
        // Around the default rating (1500): 1300, 1500 and 1700 aimed at.
        $this->addPuzzles('ML', 4, 1290, 10, ['mateIn1']);
        $this->addPuzzles('MC', 8, 1490, 5, ['mateIn1']);
        $this->addPuzzles('MH', 4, 1690, 10, ['mateIn1']);
        $alice = $this->createUserIn('alice@example.com');

        $run = $this->startStep($alice, 'mates-1');
        self::assertSame('parcours_puzzles', $run['module']);
        self::assertEquals(['tier' => 0, 'themes' => ['mateIn1'], 'count' => 10, 'rating' => 1500], $this->configOf($run['id']), 'the rating read when the step starts');
        $targets = [];
        for ($position = 0; $position < 10; ++$position) {
            $item = $this->item($alice, $run['id']);
            self::assertSame(['parcours_puzzle', 0, $position, 10, null], [$item['type'], $item['data']['tier'], $item['data']['position'], $item['data']['count'], $item['data']['lives']]);
            $targets[] = $item['data']['targetRating'];
            // Two misses: 8 out of 10, the pass counts.
            $moves = \in_array($position, [3, 6], true) ? [self::wrongFirstMove($item)] : self::solution($item['data']);
            $this->submit($alice, $run['id'], $item['id'], $moves);
        }
        self::assertSame([1300, 1300, 1300, 1500, 1500, 1500, 1500, 1700, 1700, 1700], $targets);
        $outcome = $this->outcome($alice, $run['id']);
        self::assertSame(['passed', 10, 8, 1, 10, false], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['passes'] ?? null, $outcome['required'] ?? null, $outcome['completed'] ?? null]);

        // A hint is a miss: three of them, 7 out of 10, the pass does not count.
        $again = $this->startStep($alice, 'mates-1');
        for ($position = 0; $position < 10; ++$position) {
            $item = $this->item($alice, $again['id']);
            $this->submit($alice, $again['id'], $item['id'], self::solution($item['data']), $position < 3 ? 1 : 0);
        }
        self::assertSame(['failed', 1], [$this->outcome($alice, $again['id'])['outcome'] ?? null, $this->outcome($alice, $again['id'])['passes'] ?? null]);

        $this->runOutbox();
        self::assertSame(20, $this->sum("SELECT COUNT(*) FROM activity_log_entry WHERE user_id = ? AND exercise_type = 'parcours_puzzle'", $alice), 'every puzzle counts for the day');
        self::assertSame(0, $this->sum('SELECT COUNT(*) FROM puzzle_attempt WHERE user_id = ?', $alice), 'never rated');
        self::assertSame(0, $this->sum('SELECT COUNT(*) FROM puzzle_rating WHERE user_id = ?', $alice));
    }

    public function testABossMixesItsTiersAboveTheRatingAndIsLostAtItsThirdMiss(): void
    {
        foreach (self::MATES as $i => $theme) {
            $this->addPuzzles(\chr(65 + $i).'B', 4, 1600, 60, [$theme]);
        }
        $alice = $this->createUserIn('alice@example.com');

        $run = $this->startStep($alice, 'mates-boss');
        $rounds = array_merge(...array_map(static fn (string $theme): array => [[$theme], [$theme]], self::MATES));
        self::assertEquals(['rounds' => $rounds, 'count' => 10, 'lives' => 3, 'rating' => 1500], $this->configOf($run['id']));

        $item = $this->item($alice, $run['id']);
        self::assertSame([null, ['mateIn1'], 1600, 3, 0], [$item['data']['tier'], $item['data']['themes'], $item['data']['targetRating'], $item['data']['lives'], $item['data']['failures']]);
        $this->submit($alice, $run['id'], $item['id'], self::solution($item['data']));
        for ($miss = 1; $miss <= 3; ++$miss) {
            $item = $this->item($alice, $run['id']);
            $result = $this->submit($alice, $run['id'], $item['id'], [self::wrongFirstMove($item)]);
            self::assertSame([$miss, 3 === $miss], [$result['data']['failures'], $result['data']['tierDone']]);
        }
        self::assertSame([['mateIn2'], 1667], [$item['data']['themes'], $item['data']['targetRating']], 'the fourth puzzle: the second tier, +167');

        $closed = $this->json($this->api('GET', '/api/training/runs/'.$run['id'], $alice));
        self::assertSame(['closed', 'subject_finished'], [$closed['status'] ?? null, $closed['closeReason'] ?? null]);
        $outcome = $this->outcome($alice, $run['id']);
        self::assertSame(['failed', 4, 1, false], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null, $outcome['successCount'] ?? null, $outcome['completed'] ?? null]);
    }

    public function testABossBeatenPaysItsCrystalsOnce(): void
    {
        foreach (self::MATES as $i => $theme) {
            $this->addPuzzles(\chr(65 + $i).'W', 5, 1600, 50, [$theme]);
        }
        $alice = $this->createUserIn('alice@example.com');

        $this->beatBoss($alice, 'mates-boss');
        $boss = $this->path($alice)['chapters'][0]['boss'];
        self::assertSame(['done', 150, false], [$boss['status'], $boss['chest']['crystals'] ?? null, $boss['chest']['opened'] ?? null]);

        // Beaten again: practice, nothing paid.
        $this->beatBoss($alice, 'mates-boss');
        self::assertSame(1, $this->sum("SELECT COUNT(*) FROM mind_map_chest WHERE user_id = ? AND capacity = 'challenges'", $alice));
        $this->runOutbox();
        self::assertSame(60, $this->sum("SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'mind_map'", $alice));
    }

    public function testTheFixedStepsMasteredEarnTheBadge(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $keys = [];
        foreach (['mates', 'endgames', 'tactics'] as $chapter) {
            for ($tier = 1; $tier <= 5; ++$tier) {
                $keys[] = $chapter.'-'.$tier;
            }
        }
        $last = array_pop($keys);
        foreach ($keys as $key) {
            $this->mastered($alice, $key, '2026-09-28 09:00:00');
        }
        self::assertSame([], $this->path($alice)['badges']);

        $this->mastered($alice, $last, '2026-09-29 10:00:00');
        self::assertSame([['badge' => Challenges::MASTERY_BADGE, 'step' => $last, 'earnedAt' => '2026-09-29T10:00:00+00:00']], $this->path($alice)['badges'], 'the opening chapters left out');
    }

    public function testAnOpeningChapterPerRepertoireItsBossPaidOncePerOpeningAndSide(): void
    {
        self::getContainer()->get(OpeningSynchronizer::class)->sync(__DIR__.'/../../Fixtures/Chess/openings');
        // The Sicilian for White: enough puzzles (30), and the boss's above the rating.
        $this->addPuzzles('SC', 30, 1300, 20, ['fork'], ['Sicilian_Defense']);
        $alice = $this->createUserIn('alice@example.com');
        $first = $this->sicilian($alice, 'Mon Open Sicilien');
        $second = $this->sicilian($alice, 'Ma copie');

        $chapters = $this->path($alice)['chapters'];
        $code = Challenges::code($first->getId());
        self::assertSame(['mates', 'endgames', 'tactics', 'op-'.$code, 'op-'.Challenges::code($second->getId())], array_column($chapters, 'key'), 'oldest repertoire first');
        self::assertSame(4, $chapters[3]['number']);
        self::assertSame(['repertoireId' => $first->getId()->toRfc4122(), 'name' => 'Mon Open Sicilien', 'color' => 'white', 'opening' => 'Sicilian Defense', 'family' => 'Sicilian_Defense'], $chapters[3]['meta']);
        self::assertSame(['op-'.$code.'-1', 'op-'.$code.'-2', 'op-'.$code.'-3', 'op-'.$code.'-4', 'op-'.$code.'-5'], array_column($chapters[3]['sections'][0]['steps'], 'key'));
        self::assertSame([150, 'defi-opening'], [$chapters[3]['boss']['crystals'], $chapters[3]['boss']['character']]);

        // Lines, as many as the repertoire has (one here); then puzzles of its opening.
        $lines = $this->startStep($alice, 'op-'.$code.'-1');
        self::assertSame('repertoire', $lines['module']);
        self::assertEquals(['repertoireIds' => [$first->getId()->toRfc4122()], 'unit' => 'line', 'limit' => 1], $this->configOf($lines['id']));
        $this->api('POST', '/api/training/runs/'.$lines['id'].'/stop', $alice);
        $puzzles = $this->startStep($alice, 'op-'.$code.'-2');
        self::assertEquals(['tier' => 1, 'family' => 'Sicilian_Defense', 'color' => 'w', 'count' => 10, 'rating' => 1500], $this->configOf($puzzles['id']));
        $this->api('POST', '/api/training/runs/'.$puzzles['id'].'/stop', $alice);

        $this->beatBoss($alice, 'op-'.$code.'-boss');
        $chapters = $this->path($alice)['chapters'];
        self::assertSame(150, $chapters[3]['boss']['chest']['crystals'] ?? null);
        self::assertSame(0, $chapters[4]['boss']['crystals'], 'the same opening and side: paid already');
        $this->beatBoss($alice, 'op-'.Challenges::code($second->getId()).'-boss');
        self::assertSame(['done', null], [$this->path($alice)['chapters'][4]['boss']['status'], $this->path($alice)['chapters'][4]['boss']['chest']]);
        self::assertSame(1, $this->sum("SELECT COUNT(*) FROM mind_map_chest WHERE user_id = ? AND capacity = 'challenges'", $alice));

        // A repertoire removed takes its chapter away.
        self::assertSame(204, $this->api('DELETE', '/api/repertoires/'.$second->getId()->toRfc4122(), $alice)->getStatusCode());
        self::assertCount(4, $this->path($alice)['chapters']);
        self::assertSame(404, $this->api('POST', '/api/mind-map/challenges/steps/op-'.Challenges::code($second->getId()).'-1/start', $alice)->getStatusCode());
    }

    public function testThePathsOptionsAreRefusedOutsideIt(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        foreach ([
            ['tier' => 0, 'themes' => ['fork'], 'count' => 10, 'rating' => 1500],
            ['rounds' => array_fill(0, 10, ['fork']), 'count' => 10, 'rating' => 1500],
            ['tier' => 0, 'themes' => ['fork'], 'count' => 10, 'rating' => 1500, 'lives' => 3],
        ] as $config) {
            $response = $this->api('POST', '/api/training/runs', $alice, ['module' => 'parcours_puzzles', 'subjectId' => $alice->getId()->toRfc4122(), 'budgetSeconds' => 600, 'config' => $config]);
            self::assertSame(422, $response->getStatusCode(), (string) json_encode($config));
        }
        self::assertSame(404, $this->api('POST', '/api/mind-map/challenges/steps/op-'.Challenges::code(Uuid::v7()).'-1/start', $alice)->getStatusCode(), 'no such repertoire');
    }

    /**
     * Plays a boss's ten puzzles right.
     */
    private function beatBoss(User $user, string $key): void
    {
        $run = $this->startStep($user, $key);
        for ($position = 0; $position < 10; ++$position) {
            $item = $this->item($user, $run['id']);
            $this->submit($user, $run['id'], $item['id'], self::solution($item['data']));
        }
        $outcome = $this->outcome($user, $run['id']);
        self::assertSame(['passed', 10], [$outcome['outcome'] ?? null, $outcome['itemCount'] ?? null]);
    }

    /**
     * A repertoire for White, 1. e4 c5 2. Nf3: the Sicilian (its 1. e4, King's Pawn Game, has no
     * puzzle).
     */
    private function sicilian(User $user, string $name): Repertoire
    {
        $repertoire = self::getContainer()->get(RepertoireManager::class)->create($user, $name, Color::White);
        $root = $this->entityManager->getRepository(Position::class)->findOneBy(['repertoire' => $repertoire, 'fenHash' => PositionKey::of(Rules::initial()->normalizedFen())->hash]);
        self::assertNotNull($root);
        $position = $root->getId();
        $connection = self::getContainer()->get(Connection::class);
        foreach (['e2e4', 'c7c5', 'g1f3'] as $uci) {
            $change = self::getContainer()->get(GraphEditor::class)->addMove($user, $repertoire->getId(), $position, $uci);
            $to = $connection->fetchOne('SELECT to_position_id FROM repertoire_move WHERE id = ?', [Uuid::fromString((string) $change->moveId)->toBinary()], [ParameterType::BINARY]);
            self::assertIsString($to);
            $position = Uuid::fromBinary($to);
        }
        $this->travel('+1 second');

        return $repertoire;
    }

    /**
     * @return array{id: string, module: string}
     */
    private function startStep(User $user, string $key): array
    {
        $response = $this->api('POST', '/api/mind-map/challenges/steps/'.$key.'/start', $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);

        /** @var array{id: string, module: string} */
        return $run;
    }

    /**
     * @return array<string, mixed>
     */
    private function outcome(User $user, string $runId): array
    {
        $response = $this->api('GET', '/api/mind-map/runs/'.$runId, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response);
    }

    /**
     * The run's config as the server stored it; MySQL's JSON reorders its keys, hence assertEquals.
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
     * @return TierItem
     */
    private function item(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $item = $this->json($response)['item'] ?? null;
        self::assertIsArray($item, 'The run served no item.');

        /** @var TierItem */
        return $item;
    }

    /**
     * @param list<string> $moves
     *
     * @return array{itemId: string, success: bool, data: array<string, mixed>}
     */
    private function submit(User $user, string $runId, string $itemId, array $moves, int $hintLevel = 0): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId, 'moves' => $moves, 'hintLevel' => $hintLevel]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $result = $this->json($response)['result'] ?? null;
        self::assertIsArray($result);

        /** @var array{itemId: string, success: bool, data: array<string, mixed>} */
        return $result;
    }

    /**
     * @return PathJson
     */
    private function path(User $user): array
    {
        $response = $this->api('GET', '/api/mind-map/challenges', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var PathJson */
        return $this->json($response);
    }

    private function mastered(User $user, string $step, string $at): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, 'challenges', ?, 10, ?, ?)",
            [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $step, $at, $at],
        );
    }

    /**
     * Puzzles from $rating on, $step points apart, added to the catalogue and indexed for themed
     * draws.
     *
     * @param list<string>      $themes
     * @param list<string>|null $openingTags
     */
    private function addPuzzles(string $prefix, int $count, int $rating, int $step, array $themes, ?array $openingTags = null): void
    {
        $added = [];
        for ($i = 0; $i < $count; ++$i) {
            $added[] = $entity = new Puzzle(\sprintf('%s%03d', $prefix, $i), self::WHITE_SOLVES[0], self::WHITE_SOLVES[1], $rating + $i * $step, 80, 95, 5000, $themes, 'https://lichess.org/abcdefgh', $openingTags);
            $this->catalog->persist($entity);
        }
        $this->catalog->flush();
        self::getContainer()->get(SelectionRebuilder::class)->addPuzzles(array_map(static fn (Puzzle $p): int => (int) $p->getId(), $added));
        $this->catalog->clear();
    }

    /**
     * A legal first move that is neither the solution nor a mate.
     *
     * @param TierItem $item
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

    private function sum(string $sql, User $user): int
    {
        $value = self::getContainer()->get(Connection::class)->fetchOne($sql, [$user->getId()->toBinary()]);
        self::assertTrue(is_numeric($value));

        return (int) $value;
    }
}
