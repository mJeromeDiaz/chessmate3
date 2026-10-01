<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Entity\User;
use App\Enum\Repertoire\Color;
use App\Repertoire\Graph\GraphEditor;
use App\Repertoire\RepertoireManager;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * Statistics of the repertoire test (docs/REPERTOIRE.md § 15), on histories played through the
 * training API.
 *
 * @phpstan-type Cards array{total: int, new: int, learning: int, review: int, due: int}
 * @phpstan-type Label array{opening: array{eco: string, name: string}|null, move: string|null}
 * @phpstan-type SegmentStats array{id: string, label: Label, path: list<string>, userMoveCount: int, derivedFromSegmentId: string|null, cards: array{new: int, due: int}, tests: array{total: int, succeeded: int, successRate: float|int|null, last7: int, last30: int, lastAt: string|null, lastStatus: string|null, recent: int, recentFailureRate: float|int|null}}
 * @phpstan-type OverviewJson array{repertoires: list<array{id: string, name: string, color: string, cards: Cards, tests: int, successRate30: float|int|null, lastTestedAt: string|null}>, cards: Cards, forecast: list<array{date: string, due: int}>}
 * @phpstan-type StatsJson array{id: string, cards: Cards, forecast: list<array{date: string, due: int}>, tests: array{total: int, succeeded: int, failed: int, successRate: float|int|null, last7: int, last30: int, lastAt: string|null}, segments: list<SegmentStats>, fragile: list<SegmentStats>}
 * @phpstan-type HistoryJson array{id: string, label: Label|null, path: list<string>, archived: bool, mergedIntoSegmentId: string|null, presentations: list<array{segmentId: string, beforeMerge: bool, rank: int, status: string, label: Label, moves: list<string>}>}
 * @phpstan-type ReportJson array{id: string, status: string, units: list<array{unit: string, rank: int, status: string, label: Label, segments: list<array{status: string}>}>}
 */
final class StatsApiTest extends RepertoireWebTestCase
{
    use RepertoireRunTrait;

    private const ITALIAN = '1. e4 e5 (1... c5 2. Nf3 d6 3. d4) 2. Nf3 Nc6 3. Bc4 *';

    private MockClock $clock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->clock = new MockClock('2026-10-01 10:00:00', 'UTC');
        Clock::set($this->clock);
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testTheOverviewCountsCardsAndForecastsThemByLocalDay(): void
    {
        $alice = $this->createUser('alice@example.com');
        $alice->setTimezone('Pacific/Auckland'); // UTC+13: at 12:00 UTC, already 2 October
        $this->entityManager->flush();
        $this->clock->modify('+2 hours');
        $italian = $this->repertoire($alice, self::ITALIAN);
        self::getContainer()->get(RepertoireManager::class)->create($alice, 'Vide', Color::Black);

        $run = $this->start($alice, ['repertoireIds' => [$italian->getId()->toRfc4122()], 'segmentIds' => [$this->segmentOf($italian, '', 'e4')]]);
        $item = $this->next($alice, $run['id']);
        $this->clock->modify('+4 seconds');
        self::assertSame('good', $this->submit($alice, $run['id'], $item, 'e2e4')['data']['rating'], 'learning: due again in 10 minutes');
        $this->stop($alice, $run['id']);

        $overview = $this->overview($alice);
        self::assertSame(['total' => 5, 'new' => 4, 'learning' => 1, 'review' => 0, 'due' => 0], $overview['cards']);
        self::assertSame(['Vide', 'Test'], array_column($overview['repertoires'], 'name'), 'newest first');
        self::assertSame([1, 1, '2026-10-01T12:00:04+00:00'], [$overview['repertoires'][1]['tests'], $overview['repertoires'][1]['successRate30'], $overview['repertoires'][1]['lastTestedAt']]);
        self::assertSame([0, null, null], [$overview['repertoires'][0]['tests'], $overview['repertoires'][0]['successRate30'], $overview['repertoires'][0]['lastTestedAt']]);
        self::assertSame(['date' => '2026-10-02', 'due' => 1], $overview['forecast'][0], 'today in Auckland');
        self::assertCount(7, $overview['forecast']);

        $this->clock->modify('+11 minutes');
        self::assertSame(1, $this->overview($alice)['cards']['due']);
    }

    public function testRepertoireStatsCountFirstPresentationsAndFindFragileSegments(): void
    {
        $alice = $this->createUser('alice@example.com');
        $italian = $this->repertoire($alice, self::ITALIAN);
        $id = $italian->getId()->toRfc4122();
        $c5 = $this->segmentOf($italian, 'e4', 'c5');
        $e5 = $this->segmentOf($italian, 'e4', 'e5');

        foreach ([true, true, false, true] as $i => $fail) {
            $run = $this->start($alice, ['repertoireIds' => [$id], 'segmentIds' => [$c5]]);
            $this->playUnit($alice, $run['id'], fail: $fail);
            if (0 === $i) {
                $this->playUnit($alice, $run['id']); // the retry: not a test
            }
            $this->stop($alice, $run['id']);
            $this->clock->modify('+1 day');
        }
        foreach ([1, 2, 3] as $ignored) {
            $run = $this->start($alice, ['repertoireIds' => [$id], 'segmentIds' => [$e5]]);
            $this->playUnit($alice, $run['id']);
            $this->stop($alice, $run['id']);
        }

        $stats = $this->stats($alice, $id);
        self::assertSame(['total' => 7, 'succeeded' => 4, 'failed' => 3], ['total' => $stats['tests']['total'], 'succeeded' => $stats['tests']['succeeded'], 'failed' => $stats['tests']['failed']]);
        self::assertSame([null, '1…e5', '1…c5'], array_map(static fn (array $segment): ?string => $segment['label']['move'], $stats['segments']));
        self::assertSame(["King's Pawn Game", "King's Pawn Game: Open Game", 'Sicilian Defense'], array_map(static fn (array $segment): ?string => $segment['label']['opening']['name'] ?? null, $stats['segments']));

        $bySegment = array_column($stats['segments'], null, 'id');
        self::assertSame(['e4', 'c5'], $bySegment[$c5]['path']);
        self::assertSame([4, 1, 0.75, 'failed', 2], [
            $bySegment[$c5]['tests']['total'], $bySegment[$c5]['tests']['succeeded'], $bySegment[$c5]['tests']['recentFailureRate'],
            $bySegment[$c5]['tests']['lastStatus'], $bySegment[$c5]['userMoveCount'],
        ]);
        self::assertSame([3, 1, 0], [$bySegment[$e5]['tests']['total'], $bySegment[$e5]['tests']['successRate'], $bySegment[$e5]['tests']['recentFailureRate']]);
        self::assertSame([3, 4], [$bySegment[$e5]['tests']['last7'], $bySegment[$c5]['tests']['last7']]);
        self::assertSame([$c5], array_column($stats['fragile'], 'id'), 'at least 3 tests and failures among the last ones');
    }

    public function testASegmentsHistoryKeepsRetriesAndWhatWasMergedIntoIt(): void
    {
        $alice = $this->createUser('alice@example.com');
        $italian = $this->repertoire($alice, self::ITALIAN);
        $id = $italian->getId()->toRfc4122();
        $e5 = $this->segmentOf($italian, 'e4', 'e5');
        $trunk = $this->segmentOf($italian, '', 'e4');

        $run = $this->start($alice, ['repertoireIds' => [$id], 'segmentIds' => [$e5]]);
        $this->playUnit($alice, $run['id'], fail: true);
        $this->playUnit($alice, $run['id']);
        $this->stop($alice, $run['id']);

        $history = $this->history($alice, $id, $e5);
        self::assertSame([[2, 'succeeded'], [1, 'failed']], array_map(static fn (array $row): array => [$row['rank'], $row['status']], $history['presentations']), 'newest first, the retry included');
        self::assertSame(['e5', 'Nf3', 'Nc6', 'Bc4'], $history['presentations'][0]['moves']);

        // 1...c5 deleted: no more branching point, 1...e5 merges into the trunk.
        self::getContainer()->get(GraphEditor::class)->delete($alice, $italian->getId(), Uuid::fromString($this->moveId($italian, 'e4', 'c5')));
        $merged = $this->history($alice, $id, $e5);
        self::assertSame([true, $trunk, null], [$merged['archived'], $merged['mergedIntoSegmentId'], $merged['label']]);
        $history = $this->history($alice, $id, $trunk);
        self::assertSame([true, true], array_column($history['presentations'], 'beforeMerge'));
        $segments = $this->stats($alice, $id)['segments'];
        self::assertSame([[$trunk], 3, 0], [array_column($segments, 'id'), $segments[0]['userMoveCount'], $segments[0]['tests']['total']], 'the trunk runs to 3.Bc4; its counters do not take the merged history');
    }

    public function testARunReportListsItsUnitsInOrder(): void
    {
        $alice = $this->createUser('alice@example.com');
        $italian = $this->repertoire($alice, self::ITALIAN);
        $run = $this->start($alice, ['repertoireIds' => [$italian->getId()->toRfc4122()]]);
        $this->playUnit($alice, $run['id'], fail: true);
        $this->playUnit($alice, $run['id']);
        $this->next($alice, $run['id']);
        $this->stop($alice, $run['id']);

        $report = $this->report($alice, $run['id']);
        self::assertSame('closed', $report['status']);
        self::assertSame(['failed', 'succeeded', 'interrupted'], array_column($report['units'], 'status'));
        self::assertSame(['segment'], array_values(array_unique(array_column($report['units'], 'unit'))));
        self::assertCount(1, $report['units'][0]['segments']);
    }

    public function testAnotherUsersDataIsNotFound(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $italian = $this->repertoire($alice, self::ITALIAN);
        $id = $italian->getId()->toRfc4122();
        $segment = $this->segmentOf($italian, '', 'e4');
        $run = $this->start($alice, ['repertoireIds' => [$id]]);
        $other = $this->repertoire($alice, self::ITALIAN)->getId()->toRfc4122();

        self::assertSame(404, $this->api('GET', '/api/repertoires/'.$id.'/stats', $bob)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/repertoires/'.$id.'/segments/'.$segment, $bob)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/repertoires/'.$other.'/segments/'.$segment, $alice)->getStatusCode(), 'the segment of another repertoire');
        self::assertSame(404, $this->api('GET', '/api/repertoires/runs/'.$run['id'], $bob)->getStatusCode());
        self::assertSame(404, $this->api('GET', '/api/repertoires/runs/'.Uuid::v7()->toRfc4122(), $alice)->getStatusCode());
        self::assertSame([], $this->overview($bob)['repertoires']);
    }

    /**
     * @return OverviewJson
     */
    private function overview(User $user): array
    {
        /** @var OverviewJson */
        return $this->get($user, '/api/repertoires/stats');
    }

    /**
     * @return StatsJson
     */
    private function stats(User $user, string $id): array
    {
        /** @var StatsJson */
        return $this->get($user, '/api/repertoires/'.$id.'/stats');
    }

    /**
     * @return HistoryJson
     */
    private function history(User $user, string $id, string $segmentId): array
    {
        /** @var HistoryJson */
        return $this->get($user, '/api/repertoires/'.$id.'/segments/'.$segmentId);
    }

    /**
     * @return ReportJson
     */
    private function report(User $user, string $runId): array
    {
        /** @var ReportJson */
        return $this->get($user, '/api/repertoires/runs/'.$runId);
    }

    /**
     * @return array<string, mixed>
     */
    private function get(User $user, string $uri): array
    {
        $response = $this->api('GET', $uri, $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        return $this->json($response);
    }
}
