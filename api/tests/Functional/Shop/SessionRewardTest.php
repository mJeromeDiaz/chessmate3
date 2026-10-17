<?php

declare(strict_types=1);

namespace App\Tests\Functional\Shop;

use App\Entity\User;
use App\Shop\Reward\RewardRules;
use App\Shop\Reward\Rule;
use App\Tests\Functional\Woodpecker\WoodpeckerWebTestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Crowns earned by completed sessions (docs/SHOP.md, § 4 bis, rules validated on 2026-10-14):
 * credited at the close, one minute per exercise at most, the free study unpaid, the tier of the
 * whole session, 60 paid minutes a local day, nothing for an abandoned session, the chest opened once.
 *
 * @phpstan-type RewardJson array{id: string, crowns: int, paidMinutes: int, tier: string, capped: bool, opened: bool}
 */
final class SessionRewardTest extends WoodpeckerWebTestCase
{
    public function testACompletedSessionEarnsCrownsShownInAChestOpenedOnce(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->lightSet($alice);
        $session = $this->launch($alice, [['module' => 'woodpecker', 'minutes' => 10, 'settings' => []]], 'Mardi soir');
        $run = $this->nextStep($alice, $session['id']);
        for ($i = 0; $i < 3; ++$i) {
            $this->travel('+1 minute');
            $this->playInRun($alice, $run);
        }
        // 5 minutes for 3 puzzles: 3 minutes paid, perfect (× 1.5) at 120 an hour = 9.
        $this->travel('+2 minutes');
        $this->stopRun($alice, $run);

        $reward = $this->rewardOf($alice, $session['id']);
        self::assertSame([9, 3, 'perfect', false, false], [$reward['crowns'], $reward['paidMinutes'], $reward['tier'], $reward['capped'], $reward['opened']]);
        self::assertSame(9, $this->balance($alice));
        self::assertSame(
            [['reward', 9, 9, 'Session « Mardi soir »']],
            array_map(static fn (array $row): array => [$row['kind'], self::int($row['delta']), self::int($row['balance_after']), $row['reason']], $this->entityManager->getConnection()->fetchAllAssociative(
                'SELECT kind, delta, balance_after, reason FROM shop_ledger_entry WHERE user_id = ?',
                [$alice->getId()->toBinary()],
            )),
        );
        /** @var array{member: list<array{reward: RewardJson|null}>} $listed */
        $listed = $this->json($this->api('GET', '/api/training/sessions', $alice));
        self::assertSame($reward['id'], $listed['member'][0]['reward']['id'] ?? null);

        $response = $this->api('POST', '/api/shop/rewards/'.$reward['id'].'/open', $alice);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        $opened = $this->json($response);
        self::assertSame([true, 9, 9], [$opened['opened'] ?? null, $opened['crowns'] ?? null, $opened['balance'] ?? null]);
        self::assertTrue($this->rewardOf($alice, $session['id'])['opened']);
        self::assertSame(200, $this->api('POST', '/api/shop/rewards/'.$reward['id'].'/open', $alice)->getStatusCode(), 'idempotent');
        self::assertSame(9, $this->balance($alice), 'opening credits nothing more');

        $bob = $this->createUserIn('bob@example.com');
        self::assertSame(404, $this->api('POST', '/api/shop/rewards/'.$reward['id'].'/open', $bob)->getStatusCode());
        self::assertSame(404, $this->api('POST', '/api/shop/rewards/'.Uuid::v7()->toRfc4122().'/open', $alice)->getStatusCode());
    }

    public function testTheDashboardListsTheChestsNotSeenTheActiveSessionReconciledFirst(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->lightSet($alice);
        $session = $this->launch($alice, [['module' => 'woodpecker', 'minutes' => 10, 'settings' => []]]);
        $run = $this->nextStep($alice, $session['id']);
        $this->travel('+1 minute');
        $this->playInRun($alice, $run);
        $this->travel('+1 minute');
        // The last module ended, the session not read since: still active on the server.
        $this->stopRun($alice, $run);

        /** @var array{member: list<array{id: string, crowns: int, opened: bool, balance: int, sessionId: string}>} $unopened */
        $unopened = $this->json($this->api('GET', '/api/shop/rewards/unopened', $alice));
        self::assertCount(1, $unopened['member']);
        $chest = $unopened['member'][0];
        self::assertSame([$session['id'], false, $chest['crowns']], [$chest['sessionId'], $chest['opened'], $chest['balance']]);
        self::assertGreaterThan(0, $chest['crowns']);
        self::assertSame('completed', $this->getSession($alice, $session['id'])['status']);

        $bob = $this->createUserIn('bob@example.com');
        self::assertSame([], $this->json($this->api('GET', '/api/shop/rewards/unopened', $bob))['member'] ?? null);

        self::assertSame(200, $this->api('POST', '/api/shop/rewards/'.$chest['id'].'/open', $alice)->getStatusCode());
        self::assertSame([], $this->json($this->api('GET', '/api/shop/rewards/unopened', $alice))['member'] ?? null, 'seen: gone');
        // The sibling item route is not exposed.
        self::assertSame(404, $this->api('GET', '/api/shop/rewards/'.$chest['id'], $alice)->getStatusCode());
    }

    public function testTheTierIsThatOfTheWholeSessionAndTheFreeStudyIsUnpaid(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->lightSet($alice);
        $session = $this->launch($alice, [
            ['module' => 'free', 'minutes' => 30, 'notes' => '', 'settings' => ['format' => 'book']],
            ['module' => 'woodpecker', 'minutes' => 10, 'settings' => []],
        ]);
        $free = $this->nextStep($alice, $session['id']);
        // Past its expiry (and the 2 s network tolerance).
        $this->travel('+31 minutes');
        $this->getSession($alice, $session['id']);
        self::assertNotSame($free, $run = $this->nextStep($alice, $session['id']));
        $this->travel('+1 minute');
        $this->playInRun($alice, $run);
        $this->travel('+1 minute');
        $item = $this->runNext($alice, $run)['item'];
        self::assertNotNull($item);
        $shown = $this->api('POST', '/api/training/runs/'.$run.'/submission', $alice, ['itemId' => $item['id'], 'moves' => self::solution($item['data']), 'solutionShown' => true]);
        self::assertSame(200, $shown->getStatusCode(), (string) $shown->getContent());
        $this->stopRun($alice, $run);

        // 1 solved out of 2 (missed, × 0.5); 2 minutes paid, the 30 free minutes not: 2.
        $reward = $this->rewardOf($alice, $session['id']);
        self::assertSame([2, 2, 'missed'], [$reward['crowns'], $reward['paidMinutes'], $reward['tier']]);
    }

    public function testNothingForAnAbandonedSessionNorWithoutAnExercise(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->lightSet($alice);
        $session = $this->launch($alice, [
            ['module' => 'woodpecker', 'minutes' => 10, 'settings' => []],
            ['module' => 'woodpecker', 'minutes' => 10, 'settings' => []],
        ]);
        $run = $this->nextStep($alice, $session['id']);
        $this->travel('+1 minute');
        $this->playInRun($alice, $run);
        $abandoned = $this->api('POST', '/api/training/sessions/'.$session['id'].'/abandon', $alice);
        self::assertSame('abandoned', $this->json($abandoned)['status'] ?? null);
        self::assertNull($this->json($abandoned)['reward'] ?? null);

        $empty = $this->launch($alice, [['module' => 'free', 'minutes' => 5, 'notes' => '', 'settings' => ['format' => 'book']]]);
        $this->nextStep($alice, $empty['id']);
        $this->travel('+6 minutes');
        $closed = $this->getSession($alice, $empty['id']);
        self::assertSame(['completed', null], [$closed['status'], $closed['reward']]);
        self::assertSame(0, $this->balance($alice));
    }

    public function testTheLocalDayPaysSixtyMinutesAtMost(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->lightSet($alice);
        // 59 minutes already paid today in Paris (10:00 UTC = 12:00 local).
        $this->entityManager->getConnection()->executeStatement(
            "INSERT INTO shop_session_reward (id, user_id, session_id, local_date, item_count, success_count, paid_seconds, capped, tier, crowns, created_at, opened_at)
             VALUES (?, ?, ?, '2026-09-28', 60, 60, 3540, 0, 'perfect', 177, '2026-09-28 08:00:00', '2026-09-28 08:00:00')",
            [Uuid::v7()->toBinary(), $alice->getId()->toBinary(), Uuid::v7()->toBinary()],
        );
        self::assertSame(1, $this->store($alice)['earning']['minutesLeftToday']);

        $session = $this->launch($alice, [['module' => 'woodpecker', 'minutes' => 10, 'settings' => []]]);
        $run = $this->nextStep($alice, $session['id']);
        for ($i = 0; $i < 3; ++$i) {
            $this->travel('+1 minute');
            $this->playInRun($alice, $run);
        }
        $this->stopRun($alice, $run);

        // 3 minutes earned, 1 left in the day: 1 minute perfect = 3.
        $reward = $this->rewardOf($alice, $session['id']);
        self::assertSame([3, 1, true], [$reward['crowns'], $reward['paidMinutes'], $reward['capped']]);
        $store = $this->store($alice);
        self::assertSame(0, $store['earning']['minutesLeftToday']);
        self::assertSame([120, 150, 100, 50, 60], [$store['earning']['crownsPerHour'], $store['earning']['perfectPercent'], $store['earning']['nearPercent'], $store['earning']['missedPercent'], $store['earning']['dailyMinutes']]);

        // Nothing left: the next session earns nothing, and has no chest to open.
        $next = $this->launch($alice, [['module' => 'woodpecker', 'minutes' => 10, 'settings' => []]]);
        $run = $this->nextStep($alice, $next['id']);
        $this->travel('+1 minute');
        $this->playInRun($alice, $run);
        $this->stopRun($alice, $run);
        $reward = $this->rewardOf($alice, $next['id']);
        self::assertSame([0, 0, true, true], [$reward['crowns'], $reward['paidMinutes'], $reward['capped'], $reward['opened']]);
        self::assertSame(3, $this->balance($alice));
    }

    public function testTheAdminsRuleAppliesToTheNextSessions(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->lightSet($alice);
        self::getContainer()->get(RewardRules::class)->save(new Rule(crownsPerHour: 600, perfectPercent: 200), $alice->getId(), $this->clock->now());

        $session = $this->launch($alice, [['module' => 'woodpecker', 'minutes' => 10, 'settings' => []]]);
        $run = $this->nextStep($alice, $session['id']);
        $this->travel('+1 minute');
        $this->playInRun($alice, $run);
        $this->stopRun($alice, $run);

        // 1 minute perfect at 600 an hour × 2 = 20.
        self::assertSame(20, $this->rewardOf($alice, $session['id'])['crowns']);
    }

    /**
     * @param list<array<string, mixed>> $steps
     *
     * @return array{id: string, status: string, reward: RewardJson|null}
     */
    private function launch(User $user, array $steps, string $title = ''): array
    {
        $response = $this->api('POST', '/api/training/sessions', $user, ['title' => $title, 'steps' => $steps]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{id: string, status: string, reward: RewardJson|null} */
        return $this->json($response);
    }

    /**
     * @return string the step's run id
     */
    private function nextStep(User $user, string $sessionId): string
    {
        $response = $this->api('POST', '/api/training/sessions/'.$sessionId.'/next', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var array{run: array{id: string}} $launched */
        $launched = $this->json($response);

        return $launched['run']['id'];
    }

    private function stopRun(User $user, string $runId): void
    {
        self::assertSame(200, $this->api('POST', '/api/training/runs/'.$runId.'/stop', $user)->getStatusCode());
    }

    /**
     * @return array{id: string, status: string, reward: RewardJson|null}
     */
    private function getSession(User $user, string $sessionId): array
    {
        $response = $this->api('GET', '/api/training/sessions/'.$sessionId, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{id: string, status: string, reward: RewardJson|null} */
        return $this->json($response);
    }

    /**
     * @return RewardJson
     */
    private function rewardOf(User $user, string $sessionId): array
    {
        $session = $this->getSession($user, $sessionId);
        self::assertSame('completed', $session['status']);
        self::assertNotNull($session['reward']);

        return $session['reward'];
    }

    /**
     * @return array{balance: int, earning: array{crownsPerHour: int, perfectPercent: int, nearPercent: int, missedPercent: int, dailyMinutes: int, minutesLeftToday: int}}
     */
    private function store(User $user): array
    {
        /** @var array{balance: int, earning: array{crownsPerHour: int, perfectPercent: int, nearPercent: int, missedPercent: int, dailyMinutes: int, minutesLeftToday: int}} */
        return $this->json($this->api('GET', '/api/shop', $user));
    }

    private function balance(User $user): int
    {
        return $this->store($user)['balance'];
    }

    private function lightSet(User $user): void
    {
        $response = $this->api('POST', '/api/woodpecker/sets', $user, ['name' => 'Light', 'mode' => 'light', 'ratingMin' => 400, 'ratingMax' => 3200]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}
