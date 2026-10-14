<?php

declare(strict_types=1);

namespace App\Tests\Functional\PuzzleStreak;

use App\Entity\Catalog\Puzzle;
use App\Entity\Repertoire\AutoBuild;
use App\Entity\User;
use App\Gamification\Xp\XpRules;
use App\Puzzle\Selection\SelectionRebuilder;
use App\PuzzleStreak\StreakRules;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Doctrine\DBAL\Connection;
use PChess\Chess\Chess;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puzzle streaks (docs/PUZZLE_STREAK.md): easy to hard, one miss breaks the streak, no free
 * help (the store's helps and passes are paid), never rated; the streak page plays one streak, a session step plays one after the other and
 * keeps the best; topics (general, theme, opening); records and their XP bonus; the openings
 * offered.
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 *
 * @phpstan-type PuzzleJson array{id: string, fen: string, moves: list<string>, playerColor: string, rating: int, themes: list<string>, gameUrl: string}
 * @phpstan-type StreakItem array{id: string, type: string, data: array{puzzle: PuzzleJson, ramp: string, topic: string, mode: string, streak: int, best: int, targetRating: int, startedAt: string, helps: int, aids: array{help: int, pass: int}}}
 * @phpstan-type ResultJson array{itemId: string, success: bool, data: array{status: string, mistakes: int, durationMs: int|null, streak: int, broken: bool, record: bool, best: int, aids?: array{help: int, pass: int}}}
 */
final class StreakRunTest extends WoodpeckerWebTestCase
{
    /** Black to move: the player has White, one move to find. */
    private const WHITE_SOLVES = ['r2q3r/pp1n1pk1/2pbbn1B/3pR3/3P3Q/3B4/PPP2PPP/RN4K1 b - - 9 16', 'g7g8 e5g5'];
    /** White to move: the player has Black. */
    private const BLACK_SOLVES = ['1k1r3r/6Q1/1q3p2/p3p3/2pnP1p1/2N3P1/1P4B1/3R1RK1 w - - 2 35', 'g7f6 d4e2'];
    private const SINGLE = ['ramp' => 'ladder', 'topic' => 'general', 'mode' => 'single'];
    private const TIMED = ['ramp' => 'ladder', 'topic' => 'general', 'mode' => 'timed'];

    protected function setUp(): void
    {
        parent::setUp();
        // Enough easy puzzles for the first rungs of the ladder (600, 630, 660...).
        $this->addPuzzles('ZL', 40, 560, 15, ['short']);
    }

    public function testASingleStreakEndsAtTheFirstMissNeverRated(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, self::SINGLE);
        self::assertSame(StreakRules::SINGLE_BUDGET_SECONDS, $run['budgetSeconds']);

        $xp = 0;
        $ids = [];
        for ($i = 0; $i < 3; ++$i) {
            $item = $this->item($alice, $run['id']);
            self::assertSame(['streak_puzzle', 'ladder', 'general', 'single', $i, StreakRules::LADDER_START + StreakRules::LADDER_STEP * $i], [
                $item['type'], $item['data']['ramp'], $item['data']['topic'], $item['data']['mode'], $item['data']['streak'], $item['data']['targetRating'],
            ]);
            [$low, $high] = StreakRules::ranges(\App\Enum\PuzzleStreak\Ramp::Ladder, $i)[2];
            self::assertGreaterThanOrEqual($low, $item['data']['puzzle']['rating']);
            self::assertLessThanOrEqual($high, $item['data']['puzzle']['rating']);
            self::assertSame($item['id'], $this->item($alice, $run['id'])['id'], 'A reload gets the same puzzle.');
            $ids[] = $item['data']['puzzle']['id'];

            $this->travel('+10 seconds');
            $gain = XpRules::streakPuzzle($item['data']['puzzle']['rating']);
            $xp += $gain;
            $result = $this->submitOk($alice, $run['id'], $item['id'], self::solution($item['data']), $gain);
            self::assertSame([true, 'solved', $i + 1, false, $i + 1], [$result['success'], $result['data']['status'], $result['data']['streak'], $result['data']['broken'], $result['data']['best']]);
        }
        self::assertCount(3, array_unique($ids), 'No puzzle twice in a run.');

        // A miss breaks the streak, gains nothing and ends the run.
        $missed = $this->item($alice, $run['id']);
        $result = $this->submitOk($alice, $run['id'], $missed['id'], [self::wrongFirstMove($missed)], 0);
        self::assertSame([false, 'failed', 3, true, false], [$result['success'], $result['data']['status'], $result['data']['streak'], $result['data']['broken'], $result['data']['record']]);

        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['closed', 'subject_finished', 'streak_broken'], [$closed['status'], $closed['closeReason'], $closed['summary']['context']['reason'] ?? null]);
        $metrics = $closed['summary']['metrics'] ?? [];
        self::assertSame(['single', 'ladder', 'general', 3, [3], 0, false, 3, 1], [
            $metrics['mode'] ?? null, $metrics['ramp'] ?? null, $metrics['topic'] ?? null, $metrics['bestStreak'] ?? null, $metrics['streaks'] ?? null,
            $metrics['recordBefore'] ?? null, $metrics['record'] ?? null, $metrics['solved'] ?? null, $metrics['failed'] ?? null,
        ]);
        self::assertSame([4, 3], [$closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null]);

        $review = $this->review($alice, $run['id']);
        self::assertSame(['ok', 'ok', 'ok', 'fail'], array_column($review['items'], 'status'));
        self::assertSame([0, 1, 2, 3], array_map(static fn (array $item): mixed => $item['data']['position'] ?? null, $review['items']));

        // Never rated.
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM puzzle_attempt WHERE user_id = ?', $alice));
        self::assertSame(0, $this->countRows('SELECT COUNT(*) FROM puzzle_rating WHERE user_id = ?', $alice));

        $this->runOutbox();
        self::assertSame(4, $this->countRows("SELECT COUNT(*) FROM activity_log_entry WHERE user_id = ? AND exercise_type = 'streak_puzzle'", $alice));
        self::assertSame($xp, $this->xpOf($alice));

        $overview = $this->overview($alice);
        self::assertSame(StreakRules::toArray(), $overview['rules']);
        self::assertSame([['ramp' => 'ladder', 'topic' => 'general', 'best' => 3, 'streaks' => 1]], array_map(
            static fn (array $record): array => array_diff_key($record, ['at' => true]),
            $overview['records'],
        ));
        self::assertSame(['solved' => 3, 'failed' => 1], $overview['total']);

        $this->connection()->executeStatement('DELETE FROM gamification_xp_entry');
        $command = new CommandTester((new Application($this->client->getKernel()))->find('app:gamification:rebuild'));
        self::assertSame(0, $command->execute(['--user' => $alice->getId()->toRfc4122()]));
        self::assertSame($xp, $this->xpOf($alice), 'The rebuild finds the same XP.');
    }

    public function testARecordGainsItsBonusOnce(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $first = $this->playSingle($alice, StreakRules::RECORD_MIN);
        self::assertTrue($first['record']);
        // As long as the record, not longer: no record.
        $second = $this->playSingle($alice, StreakRules::RECORD_MIN);
        self::assertFalse($second['record']);
        self::assertSame(StreakRules::RECORD_MIN, $this->getRun($alice, $second['runId'])['summary']['metrics']['recordBefore'] ?? null);

        $this->runOutbox();
        self::assertSame(1, $this->countRows("SELECT COUNT(*) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'record'", $alice));
        self::assertSame(XpRules::STREAK_RECORD, $this->countRows("SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'record'", $alice));
        $total = $this->xpOf($alice);

        $this->connection()->executeStatement('DELETE FROM gamification_xp_entry');
        $command = new CommandTester((new Application($this->client->getKernel()))->find('app:gamification:rebuild'));
        self::assertSame(0, $command->execute(['--user' => $alice->getId()->toRfc4122()]));
        self::assertSame($total, $this->xpOf($alice), 'The rebuild finds the record bonus.');
    }

    public function testABadgeIsAnnouncedByTheRunThatWinsIt(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $short = $this->playSingle($alice, 9);
        self::assertSame([], $this->getRun($alice, $short['runId'])['summary']['metrics']['badges'] ?? null);
        $first = $this->playSingle($alice, 11);
        self::assertSame(['streak_puzzles_10'], $this->getRun($alice, $first['runId'])['summary']['metrics']['badges'] ?? null);
        // A longer streak, but no new threshold: no badge again.
        $second = $this->playSingle($alice, 12);
        self::assertSame([], $this->getRun($alice, $second['runId'])['summary']['metrics']['badges'] ?? null);
    }

    public function testATimedRunPlaysStreaksOneAfterTheOtherAndKeepsTheBest(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, ['ramp' => 'tiers', 'topic' => 'general', 'mode' => 'timed'], 600);

        $this->solveInRun($alice, $run['id'], 2);
        $missed = $this->item($alice, $run['id']);
        $result = $this->submitOk($alice, $run['id'], $missed['id'], [self::wrongFirstMove($missed)]);
        self::assertTrue($result['data']['broken']);
        self::assertSame('active', $this->getRun($alice, $run['id'])['status'], 'A session step goes on after a miss.');

        // A new streak, from the easiest tier again.
        $restart = $this->item($alice, $run['id']);
        self::assertSame([0, 2, 700], [$restart['data']['streak'], $restart['data']['best'], $restart['data']['targetRating']]);
        $this->solveInRun($alice, $run['id'], 3);

        // The puzzle on screen at the end is not counted; the streak in progress ends there.
        $this->item($alice, $run['id']);
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame('stopped', $closed['closeReason']);
        self::assertSame([3, [2, 3], 5, 1], [
            $closed['summary']['metrics']['bestStreak'] ?? null, $closed['summary']['metrics']['streaks'] ?? null,
            $closed['summary']['metrics']['solved'] ?? null, $closed['summary']['metrics']['failed'] ?? null,
        ]);
        self::assertSame(['broken', 'ended'], array_map(
            static fn (mixed $status): string => \is_string($status) ? $status : '',
            $this->connection()->fetchFirstColumn('SELECT status FROM puzzle_streak WHERE user_id = ? ORDER BY started_at', [$alice->getId()->toBinary()]),
        ));
        self::assertSame(0, $this->countRows("SELECT COUNT(*) FROM puzzle_streak_attempt WHERE user_id = ? AND status = 'pending'", $alice));
    }

    public function testNoHelpAHintOrTheSolutionBreaksTheStreak(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, ['ramp' => 'ladder', 'topic' => 'general', 'mode' => 'timed'], 600);
        $item = $this->item($alice, $run['id']);
        $hinted = $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $item['id'], 'moves' => self::solution($item['data']), 'hintLevel' => 1]);
        self::assertSame('failed', $this->statusOf($hinted));

        $item = $this->item($alice, $run['id']);
        $shown = $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $item['id'], 'moves' => self::solution($item['data']), 'solutionShown' => true]);
        self::assertSame('failed', $this->statusOf($shown));
    }

    public function testAPaidHelpLightsUpThePieceAndKeepsTheStreak(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->giveAids($alice, 2, 0);
        $run = $this->startOk($alice, self::TIMED, 600);

        $item = $this->item($alice, $run['id']);
        self::assertSame([0, ['help' => 2, 'pass' => 0]], [$item['data']['helps'], $item['data']['aids']]);
        $help = $this->help($alice, $run['id'], $item['id']);
        self::assertSame(200, $help->getStatusCode(), (string) $help->getContent());
        self::assertSame(['helps' => 1, 'aids' => ['help' => 1, 'pass' => 0]], $this->json($help)['data'] ?? null);
        self::assertSame(1, $this->item($alice, $run['id'])['data']['helps'], 'A reload shows the help spent.');
        // One move to find in these puzzles: one help at most.
        $again = $this->help($alice, $run['id'], $item['id']);
        self::assertSame(409, $again->getStatusCode());
        self::assertStringContainsString('help_limit', (string) $again->getContent());

        $solved = $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $item['id'], 'moves' => self::solution($item['data']), 'hintLevel' => 1]);
        self::assertSame('solved', $this->statusOf($solved));

        // The move itself shown is still a miss, help or not.
        $item = $this->item($alice, $run['id']);
        self::assertSame(1, $item['data']['streak']);
        self::assertSame(200, $this->help($alice, $run['id'], $item['id'])->getStatusCode());
        $shown = $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $item['id'], 'moves' => self::solution($item['data']), 'hintLevel' => 2]);
        self::assertSame('failed', $this->statusOf($shown));

        // None left: refused, nothing spent.
        $item = $this->item($alice, $run['id']);
        self::assertSame(['help' => 0, 'pass' => 0], $item['data']['aids']);
        $none = $this->help($alice, $run['id'], $item['id']);
        self::assertSame(409, $none->getStatusCode());
        self::assertStringContainsString('no_help', (string) $none->getContent());
        self::assertSame(0, $this->item($alice, $run['id'])['data']['helps']);

        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        self::assertSame(2, $this->getRun($alice, $run['id'])['summary']['metrics']['helps'] ?? null);
    }

    public function testAPassKeepsTheStreakAndServesAnEquivalentPuzzle(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->giveAids($alice, 0, 1);
        $run = $this->startOk($alice, self::SINGLE);
        $this->solveInRun($alice, $run['id'], 2);

        $skipped = $this->item($alice, $run['id']);
        self::assertSame(['help' => 0, 'pass' => 1], $skipped['data']['aids']);
        // Before any move only.
        $late = $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $skipped['id'], 'moves' => [self::wrongFirstMove($skipped)], 'pass' => true]);
        self::assertSame(400, $late->getStatusCode());

        $response = $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $skipped['id'], 'moves' => [], 'pass' => true]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $step = $this->json($response);
        self::assertNull($step['xp'] ?? null, 'A passed puzzle gains nothing.');
        self::assertIsArray($step['result'] ?? null);
        /** @var ResultJson $result */
        $result = $step['result'];
        self::assertSame(['passed', 2, false, ['help' => 0, 'pass' => 0]], [$result['data']['status'], $result['data']['streak'], $result['data']['broken'], $result['data']['aids'] ?? null]);
        self::assertSame('active', $this->getRun($alice, $run['id'])['status'], 'The streak goes on.');

        // An equivalent puzzle: the same level, another puzzle.
        $next = $this->item($alice, $run['id']);
        self::assertNotSame($skipped['data']['puzzle']['id'], $next['data']['puzzle']['id']);
        self::assertSame([2, $skipped['data']['targetRating']], [$next['data']['streak'], $next['data']['targetRating']]);
        $none = $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $next['id'], 'moves' => [], 'pass' => true]);
        self::assertSame(409, $none->getStatusCode());
        self::assertStringContainsString('no_pass', (string) $none->getContent());

        $this->submitOk($alice, $run['id'], $next['id'], self::solution($next['data']));
        $missed = $this->item($alice, $run['id']);
        $this->submitOk($alice, $run['id'], $missed['id'], [self::wrongFirstMove($missed)]);

        $closed = $this->getRun($alice, $run['id']);
        $metrics = $closed['summary']['metrics'] ?? [];
        self::assertSame([3, 3, 1, 1], [$metrics['bestStreak'] ?? null, $metrics['solved'] ?? null, $metrics['failed'] ?? null, $metrics['passes'] ?? null]);
        self::assertSame([4, 3], [$closed['summary']['itemCount'] ?? null, $closed['summary']['successCount'] ?? null], 'A passed puzzle is not a played one.');
        $review = $this->review($alice, $run['id']);
        self::assertSame(['ok', 'ok', 'hint', 'ok', 'fail'], array_column($review['items'], 'status'));
        self::assertTrue($review['items'][2]['data']['passed'] ?? null);

        $this->runOutbox();
        self::assertSame(4, $this->countRows("SELECT COUNT(*) FROM activity_log_entry WHERE user_id = ? AND exercise_type = 'streak_puzzle'", $alice), 'A passed puzzle is no exercise.');
        self::assertSame(['solved' => 3, 'failed' => 1], $this->overview($alice)['total']);
    }

    public function testATheme(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->addPuzzles('ZF', 12, 580, 20, ['short', 'fork']);
        $run = $this->startOk($alice, ['ramp' => 'ladder', 'topic' => 'theme:fork', 'mode' => 'single']);
        for ($i = 0; $i < 3; ++$i) {
            $item = $this->item($alice, $run['id']);
            self::assertContains('fork', $item['data']['puzzle']['themes']);
            $this->submitOk($alice, $run['id'], $item['id'], self::solution($item['data']));
        }
    }

    public function testAnOpeningWithTheSidePlayedAndTheOpeningsOffered(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->addPuzzles('ZW', 35, 580, 10, ['short'], 'Italian_Game', self::WHITE_SOLVES);
        $this->addPuzzles('ZB', 12, 580, 10, ['short'], 'Italian_Game', self::BLACK_SOLVES);
        $this->addPuzzles('ZS', 31, 580, 10, ['short'], 'Sicilian_Defense', self::BLACK_SOLVES);

        // The user's games, written before any request (a refused run closes the entity manager):
        // the Italian with White, the Sicilian with Black (twice), the Italian with Black (too few
        // puzzles for that side: left out).
        $build = new AutoBuild($alice, 'alice', 200, ['blitz'], new \DateTimeImmutable('2026-09-28 09:00:00'), new \DateTimeImmutable('2026-09-29 09:00:00'));
        $build->batchRead((string) json_encode([
            ['fam' => 'Italian Game', 'c' => 'white'],
            ['fam' => 'Sicilian Defense', 'c' => 'black'],
            ['fam' => 'Sicilian Defense', 'c' => 'black'],
            ['fam' => 'Italian Game', 'c' => 'black'],
        ]), 4, 4, null);
        $this->entityManager->persist($build);
        $this->entityManager->flush();

        $run = $this->startOk($alice, ['ramp' => 'ladder', 'topic' => 'opening:Italian_Game:w', 'mode' => 'single']);
        for ($i = 0; $i < 3; ++$i) {
            $item = $this->item($alice, $run['id']);
            self::assertSame('white', $item['data']['puzzle']['playerColor']);
            $this->submitOk($alice, $run['id'], $item['id'], self::solution($item['data']));
        }
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);

        // A family the catalogue lacks: no run.
        self::assertSame(409, $this->start($alice, ['ramp' => 'ladder', 'topic' => 'opening:Bongcloud:w', 'mode' => 'single'])->getStatusCode());

        // A few sample puzzles belong to these families too.
        $italianWhite = 35 + $this->samples('Italian_Game', 'w');
        $italianBlack = 12 + $this->samples('Italian_Game', 'b');
        self::assertLessThan(StreakRules::MIN_OPENING_PUZZLES, $italianBlack);
        $openings = $this->openings($alice);
        self::assertSame([
            ['topic' => 'opening:Sicilian_Defense:b', 'tag' => 'Sicilian_Defense', 'color' => 'black', 'sources' => ['games'], 'puzzles' => 31 + $this->samples('Sicilian_Defense', 'b')],
            ['topic' => 'opening:Italian_Game:w', 'tag' => 'Italian_Game', 'color' => 'white', 'sources' => ['games'], 'puzzles' => $italianWhite],
        ], array_map(static fn (array $mine): array => array_diff_key($mine, ['name' => true]), $openings['mine']));
        $italian = array_values(array_filter($openings['all'], static fn (array $family): bool => 'Italian_Game' === $family['tag']));
        self::assertSame([['tag' => 'Italian_Game', 'name' => 'Italian Game', 'white' => $italianWhite, 'black' => $italianBlack]], $italian);

        // Expired, the build no longer counts.
        $this->travel('+1 day');
        self::assertSame([], $this->openings($alice)['mine']);
    }

    public function testOptionsAreChecked(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $bob = $this->createUserIn('bob@example.com');
        foreach ([
            [],
            ['topic' => 'general', 'mode' => 'single'],
            ['ramp' => 'steep', 'topic' => 'general', 'mode' => 'single'],
            ['ramp' => 'ladder', 'topic' => 'theme:crushing', 'mode' => 'single'],
            ['ramp' => 'ladder', 'topic' => 'general', 'mode' => 'blitz'],
            [...self::SINGLE, 'skips' => 1],
        ] as $config) {
            self::assertSame(422, $this->start($alice, $config)->getStatusCode(), json_encode($config, \JSON_THROW_ON_ERROR));
        }
        self::assertSame(422, $this->start($alice, self::SINGLE, 600)->getStatusCode(), 'A single streak has its own budget.');
        $response = $this->api('POST', '/api/training/runs', $bob, ['module' => 'puzzle_streak', 'subjectId' => $alice->getId()->toRfc4122(), 'budgetSeconds' => 3600, 'config' => self::SINGLE]);
        self::assertSame(404, $response->getStatusCode());
    }

    public function testASessionStepPlaysTimedStreaks(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $refused = $this->api('POST', '/api/training/sessions', $alice, ['steps' => [['module' => 'puzzle_streak', 'minutes' => 20, 'settings' => ['ramp' => 'ladder', 'topic' => 'theme:nope']]]]);
        self::assertSame(422, $refused->getStatusCode());

        $session = $this->api('POST', '/api/training/sessions', $alice, ['steps' => [['module' => 'puzzle_streak', 'minutes' => 20, 'settings' => ['ramp' => 'tiers', 'topic' => 'general']]]]);
        self::assertSame(201, $session->getStatusCode(), (string) $session->getContent());
        $id = $this->json($session)['id'] ?? null;
        self::assertIsString($id);
        $launched = $this->api('POST', '/api/training/sessions/'.$id.'/next', $alice);
        self::assertSame(200, $launched->getStatusCode(), (string) $launched->getContent());
        $run = $this->json($launched)['run'] ?? null;
        self::assertIsArray($run);
        self::assertSame([1200, 'puzzle_streak'], [$run['budgetSeconds'] ?? null, $run['module'] ?? null]);
        $item = $this->item($alice, \is_string($run['id'] ?? null) ? $run['id'] : '');
        self::assertSame(['timed', 'tiers'], [$item['data']['mode'], $item['data']['ramp']]);
    }

    public function testTimeUpWithoutAStreakAliveClosesAsUsual(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, self::TIMED, 60);
        $this->item($alice, $run['id']);

        $this->travel('+61 seconds');
        $step = $this->stepOf($alice, $run['id']);
        self::assertNull($step['item']);
        self::assertSame(['closed', 'time_up', null], [$step['run']['status'], $step['run']['closeReason'], $step['run']['decisionUntil']]);
        self::assertSame(409, $this->extend($alice, $run['id'])->getStatusCode());
    }

    public function testOvertimeGoesOnFiveMinutesAtATimeThenEndsWithTheStreak(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, self::TIMED, 60);
        self::assertSame(409, $this->extend($alice, $run['id'])->getStatusCode(), 'Time is not up.');
        $this->solveInRun($alice, $run['id'], 2);
        $onScreen = $this->item($alice, $run['id']);

        // Time up, a streak of 2 alive: nothing served, the run waits for the decision.
        $this->clock->modify($run['expiresAt']);
        $this->travel('+5 seconds');
        $step = $this->stepOf($alice, $run['id']);
        self::assertNull($step['item']);
        self::assertSame('active', $step['run']['status']);
        $expiresAt = new \DateTimeImmutable($run['expiresAt']);
        self::assertSame($expiresAt->modify('+60 seconds')->getTimestamp(), (new \DateTimeImmutable((string) $step['run']['decisionUntil']))->getTimestamp());
        self::assertSame(409, $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $onScreen['id'], 'moves' => self::solution($onScreen['data'])])->getStatusCode());
        self::assertSame('active', $this->getRun($alice, $run['id'])['status'], 'A reload still offers the decision.');

        // "Continuer": 5 more minutes from now, the same puzzle on screen.
        $extended = $this->extend($alice, $run['id']);
        self::assertSame(200, $extended->getStatusCode(), (string) $extended->getContent());
        $view = $this->json($extended);
        self::assertSame(1, $view['extensions'] ?? null);
        self::assertArrayHasKey('decisionUntil', $view);
        self::assertNull($view['decisionUntil']);
        self::assertSame($this->clock->now()->modify('+300 seconds')->getTimestamp(), (new \DateTimeImmutable(\is_string($view['expiresAt'] ?? null) ? $view['expiresAt'] : ''))->getTimestamp());
        self::assertSame($onScreen['id'], $this->item($alice, $run['id'])['id']);
        $this->submitOk($alice, $run['id'], $onScreen['id'], self::solution($onScreen['data']));

        // The same question at the end of the extension.
        $this->travel('+301 seconds');
        self::assertNotNull($this->stepOf($alice, $run['id'])['run']['decisionUntil']);
        self::assertSame(200, $this->extend($alice, $run['id'])->getStatusCode());

        // Once extended, the run ends with the streak.
        $missed = $this->item($alice, $run['id']);
        $result = $this->submitOk($alice, $run['id'], $missed['id'], [self::wrongFirstMove($missed)]);
        self::assertTrue($result['data']['broken']);
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['subject_finished', 'streak_broken', 2, 2], [
            $closed['closeReason'], $closed['summary']['context']['reason'] ?? null, $closed['extensions'], $closed['summary']['metrics']['extensions'] ?? null,
        ]);
        self::assertSame([3], $closed['summary']['metrics']['streaks'] ?? null);
    }

    public function testEndingTheStreakAtTheDecisionClosesAtTheExpiry(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, self::TIMED, 60);
        $this->solveInRun($alice, $run['id'], 2);
        $this->item($alice, $run['id']);
        $this->clock->modify($run['expiresAt']);
        $this->travel('+10 seconds');
        self::assertNotNull($this->stepOf($alice, $run['id'])['run']['decisionUntil']);

        // "Achever": closed at the expiry, the streak counted, the puzzle on screen not.
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['time_up', null, 0], [$closed['closeReason'], $closed['decisionUntil'], $closed['extensions']]);
        self::assertSame((new \DateTimeImmutable($run['expiresAt']))->getTimestamp(), (new \DateTimeImmutable((string) $closed['closedAt']))->getTimestamp());
        self::assertSame([[2], 2, 0], [$closed['summary']['metrics']['streaks'] ?? null, $closed['summary']['metrics']['solved'] ?? null, $closed['summary']['metrics']['failed'] ?? null]);
    }

    public function testWithoutAnAnswerTheRunClosesAtItsExpiry(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $run = $this->startOk($alice, self::TIMED, 60);
        $this->solveInRun($alice, $run['id'], 1);
        $this->item($alice, $run['id']);
        $this->clock->modify($run['expiresAt']);
        $this->travel('+3 seconds');
        self::assertNotNull($this->stepOf($alice, $run['id'])['run']['decisionUntil']);

        // The tab closed: past the decision time, the lazy closing closes it at its expiry.
        $this->travel('+60 seconds');
        $closed = $this->getRun($alice, $run['id']);
        self::assertSame(['closed', 'time_up'], [$closed['status'], $closed['closeReason']]);
        self::assertSame((new \DateTimeImmutable($run['expiresAt']))->getTimestamp(), (new \DateTimeImmutable((string) $closed['closedAt']))->getTimestamp());
        self::assertSame(409, $this->extend($alice, $run['id'])->getStatusCode());
        self::assertSame([1], $closed['summary']['metrics']['streaks'] ?? null);
    }

    /**
     * A single streak of $length puzzles, then a miss.
     *
     * @return array{runId: string, record: bool}
     */
    private function playSingle(User $user, int $length): array
    {
        $run = $this->startOk($user, self::SINGLE);
        $this->solveInRun($user, $run['id'], $length);
        $missed = $this->item($user, $run['id']);
        $result = $this->submitOk($user, $run['id'], $missed['id'], [self::wrongFirstMove($missed)]);

        return ['runId' => $run['id'], 'record' => $result['data']['record']];
    }

    /**
     * The selectable sample puzzles of a family where the player has $solverColor.
     */
    private function samples(string $family, string $solverColor): int
    {
        return \count(array_filter($this->selectablePuzzles(), static fn (Puzzle $p): bool => $family === ($p->getOpeningTags()[0] ?? null)
            && $solverColor === (str_contains($p->getFen(), ' b ') ? 'w' : 'b')));
    }

    private function solveInRun(User $user, string $runId, int $count): void
    {
        for ($i = 0; $i < $count; ++$i) {
            $this->travel('+5 seconds');
            $item = $this->item($user, $runId);
            $this->submitOk($user, $runId, $item['id'], self::solution($item['data']));
        }
    }

    /**
     * Puzzles from $rating on, $step points apart, added to the catalogue and indexed for themed
     * draws.
     *
     * @param list<string>          $themes
     * @param array{string, string} $puzzle FEN and moves
     */
    private function addPuzzles(string $prefix, int $count, int $rating, int $step, array $themes, ?string $family = null, array $puzzle = self::WHITE_SOLVES): void
    {
        $added = [];
        for ($i = 0; $i < $count; ++$i) {
            $added[] = $entity = new Puzzle(\sprintf('%s%03d', $prefix, $i), $puzzle[0], $puzzle[1], $rating + $i * $step, 80, 95, 5000, $themes, 'https://lichess.org/abcdefgh', null === $family ? null : [$family, $family.'_Other_Variation']);
            $this->catalog->persist($entity);
        }
        $this->catalog->flush();
        self::getContainer()->get(SelectionRebuilder::class)->addPuzzles(array_map(static fn (Puzzle $p): int => (int) $p->getId(), $added));
        $this->catalog->clear();
    }

    /**
     * @param array<string, mixed> $config
     */
    private function start(User $user, array $config, int $budget = StreakRules::SINGLE_BUDGET_SECONDS): Response
    {
        return $this->api('POST', '/api/training/runs', $user, ['module' => 'puzzle_streak', 'subjectId' => $user->getId()->toRfc4122(), 'budgetSeconds' => $budget, 'config' => $config]);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return RunJson
     */
    private function startOk(User $user, array $config, int $budget = StreakRules::SINGLE_BUDGET_SECONDS): array
    {
        $response = $this->start($user, $config, $budget);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var RunJson */
        return $this->json($response);
    }

    /**
     * @return array{run: RunJson, item: StreakItem|null}
     */
    private function stepOf(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{run: RunJson, item: StreakItem|null} */
        return $this->json($response);
    }

    private function extend(User $user, string $runId): Response
    {
        return $this->api('POST', '/api/training/runs/'.$runId.'/extend', $user);
    }

    /**
     * @return StreakItem
     */
    private function item(User $user, string $runId): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $item = $this->json($response)['item'] ?? null;
        self::assertIsArray($item, 'The run served no item.');

        /** @var StreakItem */
        return $item;
    }

    /**
     * @param list<string> $moves
     * @param int|null      $xp    the XP the response must announce, if checked
     *
     * @return ResultJson
     */
    private function submitOk(User $user, string $runId, string $itemId, array $moves, ?int $xp = null): array
    {
        $response = $this->api('POST', '/api/training/runs/'.$runId.'/submission', $user, ['itemId' => $itemId, 'moves' => $moves]);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $step = $this->json($response);
        if (null !== $xp) {
            self::assertSame($xp, $step['xp'] ?? null);
        }
        $result = $step['result'] ?? null;
        self::assertIsArray($result);

        /** @var ResultJson */
        return $result;
    }

    /**
     * The status of the puzzle a submission resolved.
     */
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
     * @return array{rules: array<string, mixed>, records: list<array{ramp: string, topic: string, best: int, streaks: int, at: string|null}>, total: array{solved: int, failed: int}}
     */
    private function overview(User $user): array
    {
        $response = $this->api('GET', '/api/puzzle-streak', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{rules: array<string, mixed>, records: list<array{ramp: string, topic: string, best: int, streaks: int, at: string|null}>, total: array{solved: int, failed: int}} */
        return $this->json($response);
    }

    /**
     * @return array{mine: list<array{topic: string, tag: string, name: string, color: string, sources: list<string>, puzzles: int}>, all: list<array{tag: string, name: string, white: int, black: int}>}
     */
    private function openings(User $user): array
    {
        $response = $this->api('GET', '/api/puzzle-streak/openings', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{mine: list<array{topic: string, tag: string, name: string, color: string, sources: list<string>, puzzles: int}>, all: list<array{tag: string, name: string, white: int, black: int}>} */
        return $this->json($response);
    }

    /**
     * A legal first move that is neither the solution nor a mate.
     *
     * @param StreakItem $item
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

    private function help(User $user, string $runId, string $itemId): Response
    {
        return $this->api('POST', '/api/training/runs/'.$runId.'/help', $user, ['itemId' => $itemId]);
    }

    /**
     * Puts the store's puzzle streak helps and passes in the user's inventory.
     */
    private function giveAids(User $user, int $helps, int $passes): void
    {
        foreach (['puzzle_streak_help' => $helps, 'puzzle_streak_pass' => $passes] as $item => $quantity) {
            $this->connection()->executeStatement(
                'INSERT INTO shop_inventory_item (user_id, item, quantity) VALUES (?, ?, ?)',
                [$user->getId()->toBinary(), $item, $quantity],
            );
        }
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
