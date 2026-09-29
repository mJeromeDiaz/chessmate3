<?php

declare(strict_types=1);

namespace App\Tests\Functional\Woodpecker;

use App\Entity\Puzzle\Puzzle;
use App\Entity\User;
use App\Woodpecker\Event\SetGrown;
use App\Woodpecker\Integration\ActiveSetExclusion;
use Doctrine\DBAL\Connection;
use Symfony\Component\Uid\Uuid;

/**
 * Light sets (docs/WOODPECKER.md), played in timed runs only. Test parameters: 10 initial
 * puzzles, growth by 5 when fewer than 2 remain unseen in the round, 30 at most; 48 selectable
 * sample puzzles.
 *
 * @phpstan-type RoundJson array{number: int, status: string, played: int, durationDays: int|null, deadlineAt: string|null, daysLeft: int|null, onTime: bool|null}
 * @phpstan-type GrowthJson array{occurredAt: string, round: int, added: int, puzzleCount: int}
 * @phpstan-type LightSetJson array{id: string, mode: string, puzzleCount: int, cycleCount: int|null, firstCycleDays: int|null, reductionFactor: float|null, minCycleDays: int|null, restDays: int|null, current: RoundJson|null, cycles: list<RoundJson>, growths: list<GrowthJson>}
 */
final class LightModeTest extends WoodpeckerWebTestCase
{
    public function testALightSetHasNoScheduleAndCoexistsWithAClassicOne(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $this->createSet($alice);
        $light = $this->createLightSet($alice, ['puzzleCount' => 300, 'cycleCount' => 5]);

        self::assertSame('light', $light['mode']);
        self::assertSame(10, $light['puzzleCount']);
        foreach (['cycleCount', 'firstCycleDays', 'reductionFactor', 'minCycleDays', 'restDays', 'current'] as $field) {
            self::assertNull($light[$field], $field);
        }
        self::assertSame([], $light['cycles']);
        self::assertSame([], $light['growths']);

        self::assertSame(409, $this->api('POST', '/api/woodpecker/sets', $alice, ['name' => 'Again', 'mode' => 'light'])->getStatusCode());
        self::assertSame(409, $this->api('POST', '/api/woodpecker/sets', $alice, ['name' => 'Again', 'puzzleCount' => 5])->getStatusCode());
        self::assertSame(422, $this->api('POST', '/api/woodpecker/sets', $alice, ['name' => 'Odd', 'mode' => 'rapid'])->getStatusCode());
        /** @var array{member: list<mixed>} $list */
        $list = $this->json($this->api('GET', '/api/woodpecker/sets', $alice));
        self::assertCount(2, $list['member']);
        self::assertSame(409, $this->api('POST', '/api/woodpecker/sets/'.$light['id'].'/attempts', $alice)->getStatusCode(), 'Timed runs only.');
    }

    public function testARoundPlaysTheSetInOrderWithoutRepeatingAPuzzle(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createLightSet($alice);
        $run = $this->startRun($alice, $set['id']);

        $played = [];
        for ($i = 0; $i < 8; ++$i) {
            $played[] = $this->playInRun($alice, $run['id'])['item']['data']['puzzle']['id'];
        }

        self::assertSame(\array_slice($this->listOf($set['id']), 0, 8), $played);
        $view = $this->lightView($alice, $set['id']);
        self::assertSame(10, $view['puzzleCount'], 'No growth while 2 puzzles remain unseen.');
        $current = $view['current'];
        self::assertNotNull($current);
        self::assertSame([1, 'active', 8, null, null, null], [$current['number'], $current['status'], $current['played'], $current['durationDays'], $current['deadlineAt'], $current['daysLeft']]);
    }

    public function testTheSetGrowsWhenTheRoundRunsOutOfUnseenPuzzles(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createLightSet($alice);
        $initial = $this->listOf($set['id']);
        $run = $this->startRun($alice, $set['id']);
        for ($i = 0; $i < 9; ++$i) {
            $this->playInRun($alice, $run['id']);
        }

        $view = $this->lightView($alice, $set['id']);
        self::assertSame(15, $view['puzzleCount']);
        self::assertSame([['round' => 1, 'added' => 5, 'puzzleCount' => 15]], array_map(
            static fn (array $growth): array => array_intersect_key($growth, array_flip(['round', 'added', 'puzzleCount'])),
            $view['growths'],
        ));
        $list = $this->listOf($set['id']);
        self::assertSame($initial, \array_slice($list, 0, 10), 'Existing positions never move.');
        self::assertCount(15, array_unique($list));

        // The rest of the original list, then the new puzzles, in order.
        self::assertSame($list[9], $this->playInRun($alice, $run['id'])['item']['data']['puzzle']['id']);
        self::assertSame($list[10], $this->playInRun($alice, $run['id'])['item']['data']['puzzle']['id']);

        $grown = array_values(array_filter($this->drainOutbox(), static fn (object $m): bool => $m instanceof SetGrown));
        self::assertCount(1, $grown);
        self::assertSame([$alice->getId()->toRfc4122(), $set['id'], 1, 5, 15], [$grown[0]->userId, $grown[0]->setId, $grown[0]->round, $grown[0]->added, $grown[0]->puzzleCount]);

        // The new puzzles leave the rated selection too.
        $ids = $this->puzzleIdsOf($set['id']);
        $excluded = self::getContainer()->get(ActiveSetExclusion::class)->excludedAmong($alice, array_map(static fn (Puzzle $p): int => (int) $p->getId(), $this->selectablePuzzles()));
        sort($excluded);
        sort($ids);
        self::assertSame($ids, $excluded);
    }

    public function testAtTheMaximumSizeTheNextRoundStartsAtOnce(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createLightSet($alice);
        $run = $this->startRun($alice, $set['id']);
        for ($i = 0; $i < 30; ++$i) {
            $this->playInRun($alice, $run['id']);
        }

        $view = $this->lightView($alice, $set['id']);
        self::assertSame(30, $view['puzzleCount'], 'Never past the maximum size.');
        self::assertSame([15, 20, 25, 30], array_column($view['growths'], 'puzzleCount'));
        self::assertSame([[1, 'completed', 30, null], [2, 'active', 0, null]], array_map(
            static fn (array $round): array => [$round['number'], $round['status'], $round['played'], $round['onTime']],
            $view['cycles'],
        ));
        self::assertSame($this->listOf($set['id'])[0], $this->playInRun($alice, $run['id'])['item']['data']['puzzle']['id'], 'Same run, puzzles repeat.');
    }

    public function testGrowthStopsWhenThePoolIsExhausted(): void
    {
        [$low, $high] = $this->ratingWindowOf(12);
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createLightSet($alice, ['ratingMin' => $low, 'ratingMax' => $high]);
        $run = $this->startRun($alice, $set['id']);
        for ($i = 0; $i < 12; ++$i) {
            $this->playInRun($alice, $run['id']);
        }

        $view = $this->lightView($alice, $set['id']);
        self::assertSame(12, $view['puzzleCount']);
        self::assertSame([2], array_column($view['growths'], 'added'), 'Only what the pool holds, and no empty growth.');
        self::assertSame([[1, 'completed'], [2, 'active']], array_map(static fn (array $r): array => [$r['number'], $r['status']], $view['cycles']));
    }

    public function testEachRunRestartsFromTheFirstPuzzleAndDropsThePuzzleOnScreen(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createLightSet($alice);
        $run = $this->startRun($alice, $set['id']);
        for ($i = 0; $i < 3; ++$i) {
            $this->playInRun($alice, $run['id']);
        }
        $this->runNext($alice, $run['id']);
        self::assertSame(200, $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice)->getStatusCode());

        $second = $this->startRun($alice, $set['id']);
        $first = $this->runNext($alice, $second['id'])['item'];
        self::assertNotNull($first);
        self::assertSame($this->listOf($set['id'])[0], $first['data']['puzzle']['id']);
        $view = $this->lightView($alice, $set['id']);
        self::assertSame([[1, 'completed', 3], [2, 'active', 0]], array_map(
            static fn (array $round): array => [$round['number'], $round['status'], $round['played']],
            $view['cycles'],
        ));
        self::assertEquals(1, self::getContainer()->get(Connection::class)->fetchOne(
            "SELECT COUNT(*) FROM woodpecker_attempt WHERE status = 'pending'",
        ), 'Only the new round\'s puzzle is pending.');
    }

    public function testPuzzlesFailedInTwoRoundsAreStubborn(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createLightSet($alice);
        $run = $this->startRun($alice, $set['id']);
        $failed = $this->playInRun($alice, $run['id'], [])['item']['data'];
        $this->api('POST', '/api/training/runs/'.$run['id'].'/stop', $alice);
        $this->playInRun($alice, $this->startRun($alice, $set['id'])['id'], []);

        /** @var array{member: list<array{puzzleId: string, failedCycles: int}>} $list */
        $list = $this->json($this->api('GET', '/api/woodpecker/sets/'.$set['id'].'/stubborn', $alice));
        self::assertSame([[$failed['puzzle']['id'], 2]], array_map(static fn (array $p): array => [$p['puzzleId'], $p['failedCycles']], $list['member']));
    }

    public function testAShuffledRoundPlaysEveryPuzzleOnce(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createLightSet($alice, ['shuffle' => true]);
        $run = $this->startRun($alice, $set['id']);
        $played = [];
        for ($i = 0; $i < 8; ++$i) {
            $played[] = $this->playInRun($alice, $run['id'])['item']['data']['puzzle']['id'];
        }

        self::assertCount(8, array_unique($played));
        self::assertSame([], array_diff($played, $this->listOf($set['id'])));
    }

    public function testAPausedLightSetCannotBePlayed(): void
    {
        $alice = $this->createUserIn('alice@example.com');
        $set = $this->createLightSet($alice);
        self::assertSame(200, $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/pause', $alice)->getStatusCode());

        self::assertSame(409, $this->api('POST', '/api/training/runs', $alice, ['module' => 'woodpecker', 'subjectId' => $set['id'], 'budgetSeconds' => 600])->getStatusCode());
        self::assertSame(200, $this->api('POST', '/api/woodpecker/sets/'.$set['id'].'/resume', $alice)->getStatusCode());
        $this->runNext($alice, $this->startRun($alice, $set['id'])['id']);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return LightSetJson
     */
    private function createLightSet(User $user, array $options = []): array
    {
        $response = $this->api('POST', '/api/woodpecker/sets', $user, $options + ['name' => 'Light', 'mode' => 'light', 'ratingMin' => 400, 'ratingMax' => 3200]);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getContent());
        /** @var LightSetJson */
        return $this->json($response);
    }

    /**
     * @return LightSetJson
     */
    private function lightView(User $user, string $setId): array
    {
        $response = $this->api('GET', '/api/woodpecker/sets/'.$setId, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
        /** @var LightSetJson */
        return $this->json($response);
    }

    /**
     * @return list<string> Lichess ids of the set's list, in order
     */
    private function listOf(string $setId): array
    {
        return array_map(static fn (mixed $id): string => \is_string($id) ? $id : '', self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT p.lichess_id FROM woodpecker_set_puzzle sp JOIN puzzle p ON p.id = sp.puzzle_id WHERE sp.set_id = :set ORDER BY sp.position',
            ['set' => Uuid::fromString($setId)->toBinary()],
        ));
    }

    /**
     * @return list<int>
     */
    private function puzzleIdsOf(string $setId): array
    {
        return array_map(static fn (mixed $id): int => is_numeric($id) ? (int) $id : 0, self::getContainer()->get(Connection::class)->fetchFirstColumn(
            'SELECT puzzle_id FROM woodpecker_set_puzzle WHERE set_id = :set',
            ['set' => Uuid::fromString($setId)->toBinary()],
        ));
    }

    /**
     * A rating range, at least 100 wide, holding exactly $size selectable sample puzzles.
     *
     * @return array{int, int}
     */
    private function ratingWindowOf(int $size): array
    {
        $ratings = array_values(array_filter(
            array_map(static fn (Puzzle $p): int => $p->getRating(), $this->selectablePuzzles()),
            static fn (int $rating): bool => $rating >= 400 && $rating <= 3200,
        ));
        sort($ratings);
        $last = \count($ratings) - 1;
        for ($i = 0; $i + $size - 1 <= $last; ++$i) {
            $low = $ratings[$i];
            $high = $ratings[$i + $size - 1];
            if ($high - $low >= 100 && (0 === $i || $ratings[$i - 1] < $low) && ($i + $size - 1 === $last || $ratings[$i + $size] > $high)) {
                return [$low, $high];
            }
        }
        self::fail(sprintf('No rating window holds exactly %d sample puzzles.', $size));
    }

    /**
     * @return list<object> the messages of the outbox, which is emptied
     */
    private function drainOutbox(): array
    {
        $messages = [];
        do {
            $batch = [...$this->outbox()->get()];
            foreach ($batch as $envelope) {
                $messages[] = $envelope->getMessage();
                $this->outbox()->ack($envelope);
            }
        } while ([] !== $batch);

        return $messages;
    }
}
