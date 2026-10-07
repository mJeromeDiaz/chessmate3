<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Activity\Event\ExerciseCompleted;
use App\Entity\Repertoire\Position;
use App\Entity\Repertoire\Repertoire;
use App\Enum\Repertoire\Color;
use App\Repertoire\Graph\GraphEditor;
use App\Repertoire\RepertoireManager;
use App\Tests\Functional\Activity\ActivityOutboxTrait;
use App\Training\Event\RunCompleted;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;
use Symfony\Component\Uid\Uuid;

/**
 * The repertoire test in timed runs (docs/REPERTOIRE.md), through the training API.
 *
 * @phpstan-import-type Item from RepertoireRunTrait
 * @phpstan-import-type Result from RepertoireRunTrait
 * @phpstan-import-type RunJson from RepertoireRunTrait
 */
final class RepertoireRunTest extends RepertoireWebTestCase
{
    use ActivityOutboxTrait;
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

    public function testASegmentIsShownFromItsContextAndItsDeviationThenComesBackWhenFailed(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->repertoire($alice, self::ITALIAN);
        $c5 = $this->segmentOf($repertoire, 'e4', 'c5');
        $run = $this->start($alice, ['repertoireIds' => [$repertoire->getId()->toRfc4122()], 'segmentIds' => [$c5]]);

        $item = $this->next($alice, $run['id']);
        $start = $item['data']['start'];
        self::assertNotNull($start);
        self::assertSame(['e2e4'], array_column($start['context'], 'uci'), 'the context is played, not asked');
        self::assertTrue($start['deviation']);
        self::assertSame('1…c5', $start['label']['move']);
        self::assertSame(['white', 'segment', 1, 1, false], [$start['orientation'], $start['unit'], $start['rank'], $start['round'], $start['retry']]);
        self::assertSame([['uci' => 'c7c5', 'san' => 'c5']], $item['data']['play']);
        self::assertSame([0, 2], [$item['data']['index'], $item['data']['total']]);
        self::assertStringNotContainsString('g1f3', (string) $this->client->getResponse()->getContent(), 'the expected move is never sent before the answer');
        self::assertSame($item, $this->next($alice, $run['id']), 'a reload gets the same item back');

        $this->clock->modify('+1 second');
        $result = $this->submit($alice, $run['id'], $item, 'g1f3', 800);
        self::assertSame(['answered', true, 'easy', false, null], [$result['data']['status'], $result['data']['correct'], $result['data']['rating'], $result['data']['unitDone'], $result['data']['unitSuccess']]);
        self::assertNull($this->lastXp(), 'no XP in the middle of a unit');

        $item = $this->next($alice, $run['id']);
        self::assertNull($item['data']['start']);
        self::assertSame([1, [['uci' => 'd7d6', 'san' => 'd6']]], [$item['data']['index'], $item['data']['play']]);
        self::assertSame([4, 'segment', 'white', '1…c5'], [$item['data']['ply'], $item['data']['unit'], $item['data']['orientation'], $item['data']['label']['move']], 'a reload in the middle of a unit can show the question');
        $result = $this->submit($alice, $run['id'], $item, 'b1c3');
        self::assertSame([false, ['uci' => 'd2d4', 'san' => 'd4'], true, false, true], [$result['data']['correct'], $result['data']['expected'], $result['data']['unitDone'], $result['data']['unitSuccess'], $result['data']['retry']]);
        self::assertSame(4, $this->lastXp(), 'XpRules: a failed unit');
        self::assertSame(409, $this->api('POST', '/api/training/runs/'.$run['id'].'/submission', $alice, ['itemId' => $item['id'], 'moves' => ['d2d4']])->getStatusCode(), 'already answered');

        $again = $this->next($alice, $run['id']);
        self::assertSame([2, true, 1], [$again['data']['start']['rank'] ?? null, $again['data']['start']['retry'] ?? null, $again['data']['start']['round'] ?? null], 'alone in its scope, it comes back at once');

        self::assertSame(
            [['failed', 4, 2, ['c5', 'Nf3', 'd6', 'd4']]],
            array_map(static fn (array $row): array => [$row['status'], $row['firstErrorPly'], $row['positionsGraded'], $row['moves']], $this->presentations("status <> 'in_progress'")),
        );
    }

    public function testTheThinkTimeIsTheClientsCappedByTheServers(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->repertoire($alice, self::ITALIAN);
        $run = $this->start($alice, ['repertoireIds' => [$repertoire->getId()->toRfc4122()], 'segmentIds' => [$this->segmentOf($repertoire, '', 'e4')]]);

        $item = $this->next($alice, $run['id']);
        $this->clock->modify('+1 second');
        self::assertSame('easy', $this->submit($alice, $run['id'], $item, 'e2e4', 9000)['data']['rating'], 'the client cannot claim more than the server saw');

        $item = $this->next($alice, $run['id']);
        $this->clock->modify('+10 seconds');
        self::assertSame('easy', $this->submit($alice, $run['id'], $item, 'e2e4', 1500)['data']['rating'], 'animations are not counted against the user');

        $item = $this->next($alice, $run['id']);
        $this->clock->modify('+10 seconds');
        self::assertSame('hard', $this->submit($alice, $run['id'], $item, 'e2e4')['data']['rating'], 'no client time: the server\'s');
    }

    public function testAFailedSegmentComesBackAfterThreeOthers(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->openBook($alice);
        $run = $this->start($alice, ['repertoireIds' => [$repertoire->getId()->toRfc4122()]]);

        $first = $this->playUnit($alice, $run['id'], fail: true);
        $keys = [];
        for ($i = 0; $i < 4; ++$i) {
            $keys[] = $this->playUnit($alice, $run['id']);
        }

        self::assertNotContains($first, \array_slice($keys, 0, 3));
        self::assertSame($first, $keys[3]);
        $summary = $this->stop($alice, $run['id'])['summary'];
        self::assertNotNull($summary);
        self::assertSame([5, 4], [$summary['itemCount'], $summary['successCount']]);
        self::assertSame(1, $summary['metrics']['recovered'] ?? null);
    }

    public function testTheQueueStartsWithOverdueCardsThenWithSegmentsFailedLastTime(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->openBook($alice);
        $id = $repertoire->getId()->toRfc4122();
        $h6 = $this->segmentOf($repertoire, 'd4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4', 'h6');
        $e6 = $this->segmentOf($repertoire, 'd4 d5 Nc3 Nf6 Bf4', 'e6');

        // 7...h6 failed: its card relearns (due in 10 min), its last presentation failed.
        $run = $this->start($alice, ['repertoireIds' => [$id], 'segmentIds' => [$h6]]);
        $this->playUnit($alice, $run['id'], fail: true);
        $this->stop($alice, $run['id']);
        $this->clock->modify('+5 minutes');
        $run = $this->start($alice, ['repertoireIds' => [$id]]);
        self::assertSame($h6, $this->playUnit($alice, $run['id']), 'failed last time, nothing overdue: first');
        $this->stop($alice, $run['id']);

        // 3...e6 learnt, overdue two months later; 7...h6 failed again just now (due in 10 min):
        // the overdue card first, then the segment failed last time.
        $run = $this->start($alice, ['repertoireIds' => [$id], 'segmentIds' => [$e6]]);
        $this->playUnit($alice, $run['id']);
        $this->stop($alice, $run['id']);
        $this->clock->modify('+60 days');
        $run = $this->start($alice, ['repertoireIds' => [$id], 'segmentIds' => [$h6]]);
        $this->playUnit($alice, $run['id'], fail: true);
        $this->stop($alice, $run['id']);
        $this->clock->modify('+1 minute');
        $run = $this->start($alice, ['repertoireIds' => [$id]]);
        self::assertSame([$e6, $h6], [$this->playUnit($alice, $run['id']), $this->playUnit($alice, $run['id'])]);
    }

    public function testRetriesInARunDoNotCountAsMoreTests(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->openBook($alice);
        $id = $repertoire->getId()->toRfc4122();
        $h6 = $this->segmentOf($repertoire, 'd4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3 e6 g4 Bg6 h4', 'h6');
        $e6 = $this->segmentOf($repertoire, 'd4 d5 Nc3 Nf6 Bf4', 'e6');

        // 7...h6: one test, failed twice then recovered in the same run (3 presentations).
        $run = $this->start($alice, ['repertoireIds' => [$id], 'segmentIds' => [$h6]]);
        $this->playUnit($alice, $run['id'], fail: true);
        $this->playUnit($alice, $run['id'], fail: true);
        $this->playUnit($alice, $run['id']);
        $this->stop($alice, $run['id']);
        // 3...e6: two tests, in two runs.
        for ($i = 0; $i < 2; ++$i) {
            $run = $this->start($alice, ['repertoireIds' => [$id], 'segmentIds' => [$e6]]);
            $this->playUnit($alice, $run['id']);
            $this->stop($alice, $run['id']);
        }

        $run = $this->start($alice, ['repertoireIds' => [$id], 'segmentIds' => [$h6, $e6]]);
        self::assertSame($h6, $this->playUnit($alice, $run['id']), 'the least tested first, nothing overdue');
    }

    public function testLinesAreAskedFromTheStartAndLoggedSegmentBySegment(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->openBook($alice);
        $run = $this->start($alice, ['repertoireIds' => [$repertoire->getId()->toRfc4122()], 'unit' => 'line']);

        $item = $this->next($alice, $run['id']);
        self::assertSame([[], [], 'line'], [$item['data']['start']['context'] ?? null, $item['data']['play'], $item['data']['start']['unit'] ?? null]);
        self::assertContains($item['data']['total'], [3 + 7, 3 + 4 + 1, 3 + 4 + 5]);
        $failed = $this->playUnit($alice, $run['id'], fail: true, first: $item);
        self::assertSame($failed, $this->playUnit($alice, $run['id']), 'a failed line comes back at once');

        $rows = $this->presentations("status <> 'in_progress'");
        self::assertGreaterThanOrEqual(4, \count($rows));
        self::assertSame(['line'], array_values(array_unique(array_column($rows, 'unit'))));
        self::assertCount(2, array_unique(array_column($rows, 'unitId')), 'one unit id per line presented');
        $summary = $this->stop($alice, $run['id'])['summary'];
        self::assertSame([2, 1, 'line'], [$summary['itemCount'] ?? null, $summary['successCount'] ?? null, $summary['metrics']['unit'] ?? null], 'the recap counts lines');
    }

    public function testAtTheEndOfTimeAUnitWithoutMistakeIsIgnoredAndWithOneIsFailed(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->openBook($alice);
        $scope = ['repertoireIds' => [$repertoire->getId()->toRfc4122()], 'segmentIds' => [$this->segmentOf($repertoire, 'd4 d5 Nc3 Nf6 Bf4', 'e6')]];
        $this->drainOutbox();

        $run = $this->start($alice, $scope, 60);
        $this->answer($alice, $run['id'], $this->next($alice, $run['id']));
        $this->clock->modify('+61 seconds');
        $closed = $this->step($alice, $run['id']);
        self::assertNull($closed['item']);
        $summary = $closed['run']['summary'];
        self::assertSame([0, 1, 1], [$summary['itemCount'] ?? null, $summary['metrics']['interrupted'] ?? null, $summary['metrics']['positionsGraded'] ?? null]);

        $run = $this->start($alice, $scope, 60);
        $this->submit($alice, $run['id'], $this->next($alice, $run['id']), 'a2a3');
        $this->clock->modify('+61 seconds');
        $summary = $this->step($alice, $run['id'])['run']['summary'];
        self::assertSame([1, 0, 0], [$summary['itemCount'] ?? null, $summary['successCount'] ?? null, $summary['metrics']['interrupted'] ?? null]);

        self::assertSame(['interrupted', 'failed'], array_column($this->presentations(), 'status'));
        $messages = $this->drainOutbox();
        $exercises = array_values(array_filter($messages, static fn (object $m): bool => $m instanceof ExerciseCompleted));
        self::assertCount(1, $exercises, 'an ignored unit emits nothing');
        self::assertSame(['repertoire_segment', false, 1, 'repertoire_presentation', $run['id'], 6, 'segment'], [
            $exercises[0]->type->value, $exercises[0]->success, $exercises[0]->itemCount, $exercises[0]->sourceType,
            $exercises[0]->metadata['trainingRunId'] ?? null, $exercises[0]->metadata['firstErrorPly'] ?? null, $exercises[0]->metadata['unit'] ?? null,
        ]);
        self::assertCount(2, array_filter($messages, static fn (object $m): bool => $m instanceof RunCompleted));
    }

    public function testAUnitWhosePreparedMoveChangedMeanwhileIsDropped(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->repertoire($alice, self::ITALIAN);
        $run = $this->start($alice, ['repertoireIds' => [$repertoire->getId()->toRfc4122()], 'segmentIds' => [$this->segmentOf($repertoire, 'e4', 'e5')]]);
        $item = $this->next($alice, $run['id']);

        self::getContainer()->get(GraphEditor::class)->replace($alice, $repertoire->getId(), Uuid::fromString($this->moveId($repertoire, 'e4 e5', 'Nf3')), 'b1c3');
        $result = $this->submit($alice, $run['id'], $item, 'g1f3');

        self::assertSame(['stale', true, null], [$result['data']['status'], $result['data']['unitDone'], $result['data']['unitSuccess']]);
        self::assertEquals([0, 0], [
            $this->connection()->fetchOne('SELECT COUNT(*) FROM repertoire_review'),
            $this->connection()->fetchOne('SELECT COUNT(*) FROM repertoire_card'),
        ], 'the new prepared move is not graded with the answer given for the old one');
        $next = $this->next($alice, $run['id']);
        self::assertSame([['uci' => 'e7e5', 'san' => 'e5']], $next['data']['play'], 'the unit as it is now');
        $summary = $this->stop($alice, $run['id'])['summary'];
        self::assertSame([0, 1], [$summary['itemCount'] ?? null, $summary['metrics']['dropped'] ?? null]);
    }

    public function testTheScopeOfALineInTheEditor(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->openBook($alice);
        $position = $this->positionAfter($repertoire, 'd4 d5 Nc3 Nf6 Bf4 Nc6 e3 Bf5 f3');
        $run = $this->start($alice, ['repertoireIds' => [$repertoire->getId()->toRfc4122()], 'rootPositionId' => $position]);

        $segments = [];
        for ($i = 0; $i < 3; ++$i) {
            $segments[] = $this->playUnit($alice, $run['id']);
        }
        self::assertCount(3, array_unique($segments), 'the 3...Nc6 segment and its two sub-segments');
        self::assertNotContains($this->segmentOf($repertoire, '', 'd4'), $segments);
    }

    public function testRunsThatCannotStart(): void
    {
        $alice = $this->createUser('alice@example.com');
        $bob = $this->createUser('bob@example.com');
        $mine = $this->repertoire($alice, self::ITALIAN)->getId()->toRfc4122();
        $bobs = $this->repertoire($bob, self::ITALIAN);
        $empty = self::getContainer()->get(RepertoireManager::class)->create($alice, 'Vide', Color::Black)->getId()->toRfc4122();

        self::assertSame(404, $this->startResponse($alice, ['repertoireIds' => [$bobs->getId()->toRfc4122()]])->getStatusCode());
        self::assertSame(404, $this->startResponse($alice, ['repertoireIds' => [$mine]], $bob->getId()->toRfc4122())->getStatusCode(), 'the subject is the user');
        self::assertSame(422, $this->startResponse($alice, ['repertoireIds' => [$mine], 'unit' => 'game'])->getStatusCode());
        self::assertSame(422, $this->startResponse($alice, [])->getStatusCode());
        self::assertSame(422, $this->startResponse($alice, ['repertoireIds' => [$mine], 'rootPositionId' => $this->positionAfter($bobs, 'e4')])->getStatusCode());
        self::assertSame(409, $this->startResponse($alice, ['repertoireIds' => [$empty]])->getStatusCode(), 'nothing to test');
        self::assertSame(409, $this->startResponse($alice, ['repertoireIds' => [$mine], 'segmentIds' => [Uuid::v7()->toRfc4122()]])->getStatusCode());
        self::assertNull($this->json($this->api('GET', '/api/training/runs/current', $alice))['hydra:member'] ?? null);
        self::assertEquals(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM training_run'));
    }

    /**
     * @return list<object>
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
