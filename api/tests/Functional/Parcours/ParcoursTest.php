<?php

declare(strict_types=1);

namespace App\Tests\Functional\Parcours;

use App\Entity\Catalog\Puzzle;
use App\Entity\User;
use App\Enum\Parcours\Effort;
use App\Enum\Parcours\Theme;
use App\Enum\Training\Module;
use App\Parcours\ParcoursRules;
use App\Puzzle\Selection\SelectionRebuilder;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use App\Training\Session\SessionManager;
use Doctrine\DBAL\Connection;
use PChess\Chess\Chess;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Parcours (docs/PARCOURS.md), launched before the défis replaced them (docs/DEFIS.md) and still
 * played to their end: a session of five tiers ended by their counts (the effort's), the puzzles
 * below, at and above the player's rating, never rated; a miss goes on; the effort's XP bonus
 * (none with a tier skipped, also on a rebuild) and a chest paid a minute per exercise; its tiers
 * only in a parcours or on the défis' path.
 *
 * @phpstan-import-type RunJson from WoodpeckerWebTestCase
 *
 * @phpstan-type PuzzleJson array{id: string, fen: string, moves: list<string>, playerColor: string, rating: int, themes: list<string>, gameUrl: string}
 * @phpstan-type TierItem array{id: string, type: string, data: array{puzzle: PuzzleJson, tier: int, themes: list<string>, family: string|null, position: int, count: int, targetRating: int, startedAt: string}}
 * @phpstan-type SessionJson array{id: string, status: string, currentIndex: int, expiresAt: string, steps: list<array{module: string, status: string}>, parcours: array{theme: string, effort: string, repertoireId: string|null}|null, reward: array{crowns: int, paidMinutes: int, tier: string, capped: bool, opened: bool}|null}
 */
final class ParcoursTest extends WoodpeckerWebTestCase
{
    /** Black to move: the player has White, one move to find. */
    private const WHITE_SOLVES = ['r2q3r/pp1n1pk1/2pbbn1B/3pR3/3P3Q/3B4/PPP2PPP/RN4K1 b - - 9 16', 'g7g8 e5g5'];

    public function testALightParcoursChainsItsFiveTiersBelowAtAndAboveTheRatingAndEarnsItsRewards(): void
    {
        foreach (['mateIn1', 'mateIn2', 'mateIn3', 'mateIn4', 'mateIn5'] as $i => $theme) {
            // Around the default rating (1500): 1300, 1500 and 1700 aimed at in each tier.
            $this->addPuzzles(\chr(65 + $i).'L', 3, 1290, 10, [$theme]);
            $this->addPuzzles(\chr(65 + $i).'C', 3, 1490, 10, [$theme]);
            $this->addPuzzles(\chr(65 + $i).'H', 3, 1690, 10, [$theme]);
        }
        $alice = $this->createUserIn('alice@example.com');

        $session = $this->launch($alice, ['theme' => 'mates', 'effort' => 'light']);
        self::assertSame(['theme' => 'mates', 'effort' => 'light', 'repertoireId' => null], $session['parcours']);
        self::assertSame(array_fill(0, 5, 'parcours_puzzles'), array_column($session['steps'], 'module'));
        self::assertSame(404, $this->api('POST', '/api/parcours', $alice, ['theme' => 'tactics', 'effort' => 'light'])->getStatusCode(), 'no parcours launched any more');

        for ($tier = 0; $tier < 5; ++$tier) {
            $run = $this->nextTier($alice, $session['id']);
            // Ended by its count: the clock is only the parcours' expiry, its local midnight.
            self::assertSame(strtotime($session['expiresAt']), strtotime($run['expiresAt']));
            self::assertGreaterThan(3600, $run['budgetSeconds']);
            $targets = [];
            for ($position = 0; $position < 3; ++$position) {
                $this->travel('+20 seconds');
                $item = $this->item($alice, $run['id']);
                self::assertSame(['parcours_puzzle', $tier, $position, 3], [$item['type'], $item['data']['tier'], $item['data']['position'], $item['data']['count']]);
                self::assertContains('mateIn'.($tier + 1), $item['data']['puzzle']['themes']);
                $targets[] = $item['data']['targetRating'];
                // One miss in the second tier: the solution is shown, the tier goes on.
                $moves = 1 === $tier && 1 === $position ? [self::wrongFirstMove($item)] : self::solution($item['data']);
                $result = $this->submit($alice, $run['id'], $item['id'], $moves);
                self::assertSame(2 === $position, $result['data']['tierDone'] ?? null);
            }
            self::assertSame([1300, 1500, 1700], $targets);
            $closed = $this->getRun($alice, $run['id']);
            self::assertSame(['closed', 'subject_finished', 'tier_done'], [$closed['status'], $closed['closeReason'], $closed['summary']['context']['reason'] ?? null]);
        }

        $done = $this->getSession($alice, $session['id']);
        self::assertSame('completed', $done['status']);
        // 15 exercises paid a minute each, 14/15 solved (near, × 1) at 120 an hour: 30 crowns.
        self::assertSame([30, 15, 'near', false, false], [$done['reward']['crowns'] ?? null, $done['reward']['paidMinutes'] ?? null, $done['reward']['tier'] ?? null, $done['reward']['capped'] ?? null, $done['reward']['opened'] ?? null]);

        $this->runOutbox();
        self::assertSame(25, $this->sum("SELECT COALESCE(SUM(xp), 0) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'session'", $alice), 'the light effort\'s bonus');
        self::assertSame(15, $this->sum("SELECT COUNT(*) FROM activity_log_entry WHERE user_id = ? AND exercise_type = 'parcours_puzzle'", $alice), 'every puzzle counts for the day');
        self::assertSame(0, $this->sum('SELECT COUNT(*) FROM puzzle_attempt WHERE user_id = ?', $alice), 'never rated');
        self::assertSame(0, $this->sum('SELECT COUNT(*) FROM puzzle_rating WHERE user_id = ?', $alice));
    }

    public function testAHintOrTheSolutionIsAMissAndATierNeverRepeatsAPuzzle(): void
    {
        $this->addPuzzles('FK', 30, 1200, 20, ['fork']);
        $alice = $this->createUserIn('alice@example.com');
        $session = $this->launch($alice, ['theme' => 'tactics', 'effort' => 'light']);
        $run = $this->nextTier($alice, $session['id']);

        $item = $this->item($alice, $run['id']);
        self::assertSame('failed', $this->submit($alice, $run['id'], $item['id'], self::solution($item['data']), hintLevel: 1)['data']['status'] ?? null, 'a hint is a miss');
        $ids = [$item['data']['puzzle']['id']];
        for ($i = 0; $i < 2; ++$i) {
            $item = $this->item($alice, $run['id']);
            $ids[] = $item['data']['puzzle']['id'];
            $this->submit($alice, $run['id'], $item['id'], self::solution($item['data']));
        }
        self::assertCount(3, array_unique($ids));
    }

    public function testASkippedTierWithholdsTheBonusAlsoOnARebuild(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $session = $this->launch($alice, ['theme' => 'tactics', 'effort' => 'intense']);
        for ($tier = 0; $tier < 5; ++$tier) {
            $skipped = $this->api('POST', '/api/training/sessions/'.$session['id'].'/skip', $alice);
            self::assertSame(200, $skipped->getStatusCode(), (string) $skipped->getContent());
        }
        self::assertSame('completed', $this->getSession($alice, $session['id'])['status']);

        $this->runOutbox();
        $bonus = "SELECT COUNT(*) FROM gamification_xp_entry WHERE user_id = ? AND kind = 'session'";
        self::assertSame(0, $this->sum($bonus, $alice), 'no bonus for a parcours not played to its end');
        $command = new CommandTester((new Application($this->client->getKernel()))->find('app:gamification:rebuild'));
        self::assertSame(0, $command->execute(['--user' => $alice->getId()->toRfc4122()]));
        self::assertSame(0, $this->sum($bonus, $alice), 'nor after a rebuild');
    }

    public function testItsTiersArePlayedInAParcoursOnly(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $handMade = $this->api('POST', '/api/training/sessions', $alice, ['steps' => [['module' => 'parcours_puzzles', 'minutes' => 10, 'settings' => ['tier' => 0, 'themes' => ['fork'], 'count' => 3]]]]);
        self::assertSame(422, $handMade->getStatusCode());
        $alone = $this->api('POST', '/api/training/runs', $alice, ['module' => 'parcours_puzzles', 'subjectId' => $alice->getId()->toRfc4122(), 'budgetSeconds' => 600, 'config' => ['tier' => 0, 'themes' => ['fork'], 'count' => 3, 'rating' => 1500]]);
        self::assertSame(422, $alone->getStatusCode(), (string) $alone->getContent());
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return SessionJson
     */
    private function launch(User $user, array $body): array
    {
        self::assertIsString($body['theme']);
        self::assertIsString($body['effort']);
        $theme = Theme::from($body['theme']);
        $effort = Effort::from($body['effort']);
        // As the parcours were launched (the former ParcoursLauncher).
        $steps = [];
        for ($tier = 0; $tier < ParcoursRules::TIERS; ++$tier) {
            $steps[] = ['module' => Module::ParcoursPuzzles, 'minutes' => 60, 'notes' => '', 'settings' => ['tier' => $tier, 'themes' => ParcoursRules::tier($theme, $tier)['themes'], 'count' => $effort->perTier()]];
        }
        $session = self::getContainer()->get(SessionManager::class)->create($user, 'Parcours', '', $steps, null, ['theme' => $theme->value, 'effort' => $effort->value, 'repertoireId' => null]);

        return $this->getSession($user, $session->getId()->toRfc4122());
    }

    /**
     * @return RunJson
     */
    private function nextTier(User $user, string $sessionId): array
    {
        $response = $this->api('POST', '/api/training/sessions/'.$sessionId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $run = $this->json($response)['run'] ?? null;
        self::assertIsArray($run);

        /** @var RunJson */
        return $run;
    }

    /**
     * @return SessionJson
     */
    private function getSession(User $user, string $sessionId): array
    {
        $response = $this->api('GET', '/api/training/sessions/'.$sessionId, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var SessionJson */
        return $this->json($response);
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
     * Puzzles from $rating on, $step points apart, added to the catalogue and indexed for themed
     * draws.
     *
     * @param list<string> $themes
     */
    private function addPuzzles(string $prefix, int $count, int $rating, int $step, array $themes): void
    {
        $added = [];
        for ($i = 0; $i < $count; ++$i) {
            $added[] = $entity = new Puzzle(\sprintf('%s%03d', $prefix, $i), self::WHITE_SOLVES[0], self::WHITE_SOLVES[1], $rating + $i * $step, 80, 95, 5000, $themes, 'https://lichess.org/abcdefgh', null);
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
