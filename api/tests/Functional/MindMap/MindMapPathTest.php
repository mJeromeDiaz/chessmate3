<?php

declare(strict_types=1);

namespace App\Tests\Functional\MindMap;

use App\Entity\User;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * The mind map paths (docs/MIND_MAP.md): the visualisation path's state, a step played as a run of
 * its module and judged when it closes (exercise 1.1: a one-minute coordinates series, 10 answers
 * at 80 %; 1.2 and 1.3: the name and colour modes), its passes and XP, skips, refusals, the chest on the way, practice, privacy.
 *
 * @phpstan-type StepJson array{key: string, number: string|null, kind: string, status: string, passes: int, required: int, crystals: int, xp: int, character: string|null, badges: list<string>, skippable: bool, chest: array{id: string, step: string, crystals: int, opened: bool}|null}
 * @phpstan-type SectionJson array{key: string, number: string|null, status: string, steps: list<StepJson>, miniBosses: list<StepJson>, hidden: list<StepJson>}
 * @phpstan-type PathJson array{capacity: string, chapters: list<array{key: string, number: int, status: string, sections: list<SectionJson>, boss: StepJson}>, badges: list<array{badge: string, step: string, earnedAt: string}>, unopenedChests: list<array{id: string, step: string, crystals: int, opened: bool}>}
 * @phpstan-type OutcomeJson array{id: string, capacity: string, step: string, kind: string, outcome: string, itemCount: int, successCount: int, count: int, lives: int|null, passes: int, required: int, completed: bool, xp: int, chest: array{id: string, step: string, crystals: int, opened: bool}|null, unopenedChests: list<array{id: string, step: string, crystals: int, opened: bool}>}
 */
final class MindMapPathTest extends WoodpeckerWebTestCase
{
    public function testThePathAtTheStart(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $path = $this->path($alice);

        self::assertSame('visualisation', $path['capacity']);
        self::assertSame(['open', 'locked', 'locked', 'locked', 'locked', 'locked', 'locked'], array_column($path['chapters'], 'status'));
        $steps = $path['chapters'][0]['sections'][0]['steps'];
        self::assertSame(['1-1', 'open', 0, 2, true], [$steps[0]['key'], $steps[0]['status'], $steps[0]['passes'], $steps[0]['required'], $steps[0]['skippable']]);
        self::assertSame(['locked', false], [$steps[1]['status'], $steps[1]['skippable']]);
        self::assertSame([['1-chest', 'chest', 60], ['1-chest-2', 'chest', 60]], [[$steps[3]['key'], $steps[3]['kind'], $steps[3]['crystals']], [$steps[7]['key'], $steps[7]['kind'], $steps[7]['crystals']]], 'After 1.3, and after 1.6.');
        $boss = $path['chapters'][0]['boss'];
        self::assertSame(['boss', 'open', 'jongleur', 200, 100], [$boss['kind'], $boss['status'], $boss['character'], $boss['crystals'], $boss['xp']], 'Announced at the top of its chapter, open at once.');
        $hidden = $path['chapters'][1]['sections'][2]['hidden'][0];
        self::assertSame(['2-3-hidden', 'hidden', null, 0, []], [$hidden['key'], $hidden['status'], $hidden['character'], $hidden['crystals'], $hidden['badges']], 'A hidden boss shows nothing of itself.');
        self::assertSame([[], []], [$path['badges'], $path['unopenedChests']]);
    }

    public function testTheCalculationPathOpensOnItsFirstLesson(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $path = $this->path($alice, 'calculation');

        self::assertSame(['calculation', ['open', 'locked', 'locked']], [$path['capacity'], array_column($path['chapters'], 'status')]);
        self::assertSame(['open', 'locked'], [$this->step($path, '1-1')['status'], $this->step($path, '1-2')['status']]);
        $boss = $this->step($path, '1-boss');
        self::assertSame(['open', 'metronome', 200], [$boss['status'], $boss['character'], $boss['crystals']], 'Open with its chapter.');
        $this->assertRefused($this->api('POST', '/api/mind-map/calculation/steps/2-boss/start', $alice), 409, 'step_locked');
        self::assertSame(200, $this->api('POST', '/api/mind-map/calculation/steps/1-1/skip', $alice)->getStatusCode(), 'An exercise may be skipped.');
        self::assertSame('open', $this->step($this->path($alice), '1-1')['status'], 'The visualisation path is apart.');
    }

    public function testAnExerciseIsValidatedByItsPassesThenEarnsItsXp(): void
    {
        $alice = $this->createUserIn('alice@example.com');

        $first = $this->playExercise($alice, 10);
        self::assertSame(['1-1', 'exercise', 'passed', 10, 10, 10, null, 1, 2, false, 0, null], [
            $first['step'], $first['kind'], $first['outcome'], $first['itemCount'], $first['successCount'], $first['count'], $first['lives'],
            $first['passes'], $first['required'], $first['completed'], $first['xp'], $first['chest'],
        ]);
        self::assertSame('open', $this->step($this->path($alice), '1-1')['status'], 'A pass of two.');

        $second = $this->playExercise($alice, 10, 2);
        self::assertSame(['passed', 2, true, 15], [$second['outcome'], $second['passes'], $second['completed'], $second['xp']], 'Two wrong answers out of ten: 80 %.');
        $path = $this->path($alice);
        self::assertSame(['done', 'open', true], [$this->step($path, '1-1')['status'], $this->step($path, '1-2')['status'], $this->step($path, '1-2')['skippable']]);

        $this->runOutbox();
        self::assertSame(15, $this->mindMapXpOf($alice));
        self::assertSame(15, $this->runXp($alice, $second['id']), 'The bonus belongs to the run that earned it.');
        $command = new CommandTester((new Application($this->client->getKernel()))->find('app:gamification:rebuild'));
        self::assertSame(0, $command->execute(['--user' => $alice->getId()->toRfc4122()]));
        self::assertSame(15, $this->mindMapXpOf($alice), 'The rebuild finds it again.');
    }

    public function testAFailedLessonCountsNothing(): void
    {
        $alice = $this->createUserIn('alice@example.com');

        self::assertSame(['failed', 0], array_values(array_intersect_key($this->playExercise($alice, 9), ['outcome' => 0, 'passes' => 0])), 'Nine answers: not enough.');
        self::assertSame(['failed', 0], array_values(array_intersect_key($this->playExercise($alice, 10, 3), ['outcome' => 0, 'passes' => 0])), '70 %.');
        self::assertSame(0, $this->step($this->path($alice), '1-1')['passes']);
    }

    public function testRefusals(): void
    {
        $alice = $this->createUserIn('alice@example.com');

        $this->assertRefused($this->api('POST', '/api/mind-map/visualisation/steps/1-2/start', $alice), 409, 'step_locked');
        $this->assertRefused($this->api('POST', '/api/mind-map/visualisation/steps/1-3/skip', $alice), 409, 'step_locked');
        $this->assertRefused($this->api('POST', '/api/mind-map/visualisation/steps/1-boss/skip', $alice), 409, 'step_not_skippable');
        $this->assertRefused($this->api('POST', '/api/mind-map/visualisation/steps/1-chest/skip', $alice), 409, 'step_not_skippable');
        self::assertSame(404, $this->api('POST', '/api/mind-map/visualisation/steps/9-9/start', $alice)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/mind-map/calcul', $alice)->getStatusCode());

        $skipped = $this->json($this->api('POST', '/api/mind-map/visualisation/steps/1-1/skip', $alice));
        self::assertIsArray($skipped['path'] ?? null);
        /** @var PathJson $path */
        $path = $skipped['path'];
        self::assertSame(['skipped', 'open'], [$this->step($path, '1-1')['status'], $this->step($path, '1-2')['status']]);
        // 1.7 needs a repertoire of 10 segments (docs/NOTATION.md); every exercise skipped, the boss
        // opens, and is never skipped.
        foreach (['1-2', '1-3', '1-4', '1-5', '1-6'] as $key) {
            self::assertSame(200, $this->api('POST', '/api/mind-map/visualisation/steps/'.$key.'/skip', $alice)->getStatusCode(), $key);
        }
        $this->assertRefused($this->api('POST', '/api/mind-map/visualisation/steps/1-7/start', $alice), 409, 'no_repertoire');
        foreach (['1-7', '1-8', '1-9'] as $key) {
            self::assertSame(200, $this->api('POST', '/api/mind-map/visualisation/steps/'.$key.'/skip', $alice)->getStatusCode(), $key);
        }
        self::assertSame('open', $this->step($this->path($alice), '1-boss')['status']);
        self::assertSame(200, $this->api('POST', '/api/mind-map/visualisation/steps/1-1/skip', $alice)->getStatusCode(), 'Skipped again: nothing changes.');

        // A skipped exercise may be played: passed, it is done.
        $this->playExercise($alice, 10);
        self::assertSame('skipped', $this->step($this->path($alice), '1-1')['status'], 'A pass of two.');
        $this->playExercise($alice, 10);
        self::assertSame('done', $this->step($this->path($alice), '1-1')['status']);
        $this->assertRefused($this->api('POST', '/api/mind-map/visualisation/steps/1-1/skip', $alice), 409, 'step_not_skippable');

        // One run at a time.
        self::assertSame(201, $this->api('POST', '/api/mind-map/visualisation/steps/1-1/start', $alice)->getStatusCode());
        self::assertSame(409, $this->api('POST', '/api/mind-map/visualisation/steps/1-1/start', $alice)->getStatusCode());
    }

    public function testTheChestOnTheWayIsEarnedByPassesThenOpened(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        foreach (['1-2', '1-3'] as $step) {
            $this->completed($alice, $step);
        }

        $this->playExercise($alice, 10);
        $outcome = $this->playExercise($alice, 10);
        self::assertTrue($outcome['completed']);
        self::assertNull($outcome['chest'], 'An exercise has no chest of its own.');
        self::assertSame([['1-chest', 60, false]], array_map(static fn (array $chest): array => [$chest['step'], $chest['crystals'], $chest['opened']], $outcome['unopenedChests']));
        self::assertSame(60, $this->balanceOf($alice), 'Credited when earned.');
        $path = $this->path($alice);
        self::assertSame(['done', 'open', 'locked'], [$this->step($path, '1-chest')['status'], $this->step($path, '1-4')['status'], $this->step($path, '1-chest-2')['status']], 'The chest after 1.3; the second waits for 1.4 to 1.6.');
        self::assertSame(60, $this->step($path, '1-chest')['chest']['crystals'] ?? null);

        $chestId = $outcome['unopenedChests'][0]['id'];
        $response = $this->api('POST', '/api/mind-map/chests/'.$chestId.'/open', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        self::assertSame([$chestId, '1-chest', 60, true, 60], array_values(array_intersect_key($this->json($response), array_flip(['id', 'step', 'crystals', 'opened', 'balance']))));
        self::assertSame(200, $this->api('POST', '/api/mind-map/chests/'.$chestId.'/open', $alice)->getStatusCode(), 'Idempotent.');
        self::assertSame([], $this->path($alice)['unopenedChests']);
        self::assertSame(60, $this->balanceOf($alice), 'Paid once.');

        // Practice: played again, judged, never paid again.
        $practice = $this->playExercise($alice, 10);
        self::assertSame(['passed', false, 0], [$practice['outcome'], $practice['completed'], $practice['xp']]);
        self::assertSame(60, $this->balanceOf($alice));
        $this->runOutbox();
        self::assertSame(15, $this->mindMapXpOf($alice), 'Exercise 1.1 only (1.2 and 1.3 set as done here); the chest has no XP.');

        // 1.4: a counted lesson of the notation module (docs/NOTATION.md), its clock only an expiry.
        $response = $this->api('POST', '/api/mind-map/visualisation/steps/1-4/start', $alice);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);
        self::assertSame(['notation', 1_800], [$run['module'] ?? null, $run['budgetSeconds'] ?? null]);
    }

    public function testRunsAndChestsArePrivate(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        foreach (['1-2', '1-3', '1-4'] as $step) {
            $this->completed($alice, $step);
        }
        $this->playExercise($alice, 10);
        $outcome = $this->playExercise($alice, 10);

        self::assertSame(404, $this->api('GET', '/api/mind-map/runs/'.$outcome['id'], $bob)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/mind-map/chests/'.$outcome['unopenedChests'][0]['id'].'/open', $bob)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/mind-map/runs/'.Uuid::v7()->toRfc4122(), $alice)->getStatusCode(), 'Sibling route: a run, not a capacity.');
        self::assertSame(404, $this->api('POST', '/api/mind-map/chests/'.Uuid::v7()->toRfc4122().'/open', $alice)->getStatusCode());
        self::assertSame(0, $this->balanceOf($bob));
    }

    /**
     * Plays exercise 1.1 once: a one-minute coordinates series with $count answers, $wrong of them
     * wrong, then lets the time run out.
     *
     * @return OutcomeJson
     */
    private function playExercise(User $user, int $count, int $wrong = 0): array
    {
        $response = $this->api('POST', '/api/mind-map/visualisation/steps/1-1/start', $user);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);
        self::assertSame(['coordinates', 60], [$run['module'] ?? null, $run['budgetSeconds'] ?? null]);
        $runId = $run['id'] ?? null;
        self::assertIsString($runId);

        $next = $this->json($this->api('POST', '/api/training/runs/'.$runId.'/next', $user));
        self::assertIsArray($next['item'] ?? null);
        /** @var array{id: string, data: array{squares: list<string>}} $item */
        $item = $next['item'];
        $squares = $item['data']['squares'];
        $answers = [];
        for ($i = 0; $i < $count; ++$i) {
            $square = $i < $wrong ? ('a1' === $squares[$i] ? 'h8' : 'a1') : $squares[$i];
            $answers[] = ['index' => $i, 'square' => $square, 'ms' => 1_000];
        }
        $this->travel('+20 seconds');
        $submitted = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $item['id'], 'answers' => $answers]);
        self::assertSame(200, $submitted->getStatusCode(), (string) $submitted->getContent());
        $this->travel('+45 seconds');

        $response = $this->api('GET', '/api/mind-map/runs/'.$runId, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var OutcomeJson */
        return $this->json($response);
    }

    /**
     * @return PathJson
     */
    private function path(User $user, string $capacity = 'visualisation'): array
    {
        $response = $this->api('GET', '/api/mind-map/'.$capacity, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var PathJson */
        return $this->json($response);
    }

    /**
     * @param PathJson $path
     *
     * @return StepJson
     */
    private function step(array $path, string $key): array
    {
        foreach ($path['chapters'] as $chapter) {
            foreach ($chapter['sections'] as $section) {
                foreach ([...$section['steps'], ...$section['miniBosses'], ...$section['hidden']] as $step) {
                    if ($step['key'] === $key) {
                        return $step;
                    }
                }
            }
            if ($chapter['boss']['key'] === $key) {
                return $chapter['boss'];
            }
        }
        self::fail("No step $key.");
    }

    private function assertRefused(Response $response, int $status, string $reason): void
    {
        self::assertSame($status, $response->getStatusCode(), (string) $response->getContent());
        self::assertStringContainsString($reason, (string) $response->getContent());
    }

    /** An exercise without module yet, set as done (as its lot will make it playable). */
    private function completed(User $user, string $step): void
    {
        $this->connection()->executeStatement(
            "INSERT INTO mind_map_progress (id, user_id, capacity, step, passes, completed_at, updated_at) VALUES (?, ?, 'visualisation', ?, 2, '2026-09-28 09:00:00', '2026-09-28 09:00:00')",
            [Uuid::v7()->toBinary(), $user->getId()->toBinary(), $step],
        );
    }

    private function mindMapXpOf(User $user): int
    {
        $sum = $this->connection()->fetchOne("SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'mind_map'", [$user->getId()->toBinary()]);
        self::assertTrue(is_numeric($sum));

        return (int) $sum;
    }

    private function runXp(User $user, string $runId): int
    {
        $sum = $this->connection()->fetchOne(
            "SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'mind_map' AND training_run_id = ?",
            [$user->getId()->toBinary(), Uuid::fromString($runId)->toBinary()],
        );
        self::assertTrue(is_numeric($sum));

        return (int) $sum;
    }

    private function balanceOf(User $user): int
    {
        $balance = $this->connection()->fetchOne('SELECT balance FROM shop_wallet WHERE user_id = ?', [$user->getId()->toBinary()]);

        return is_numeric($balance) ? (int) $balance : 0;
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }
}
