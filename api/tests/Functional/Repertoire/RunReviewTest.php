<?php

declare(strict_types=1);

namespace App\Tests\Functional\Repertoire;

use App\Chess\Rules;
use App\Entity\User;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

/**
 * The review of a repertoire run (docs/TRAINING.md, run review): one item per unit presented, its
 * moves from the position its first segment starts from (kept with the presentation).
 *
 * @phpstan-type UnitJson array{index: int, type: string, status: string, durationMs: int|null, data: array{unitId: string, unit: string, rank: int, round: int, repertoireId: string, repertoireName: string, orientation: string, label: array{opening: mixed, move: string|null}, startFen: string|null, moves: list<string>, firstErrorPly: int|null}}
 */
final class RunReviewTest extends RepertoireWebTestCase
{
    use RepertoireRunTrait;

    private const ITALIAN = '1. e4 e5 (1... c5 2. Nf3 d6 3. d4) 2. Nf3 Nc6 3. Bc4 *';

    protected function setUp(): void
    {
        parent::setUp();
        Clock::set(new MockClock('2026-10-01 10:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Clock::set(new NativeClock());
        parent::tearDown();
    }

    public function testASegmentFailedThenSucceededIsReviewedTwiceFromWhereItStarts(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->repertoire($alice, self::ITALIAN);
        $c5 = $this->segmentOf($repertoire, 'e4', 'c5');
        $run = $this->start($alice, ['repertoireIds' => [$repertoire->getId()->toRfc4122()], 'segmentIds' => [$c5]]);
        $this->playUnit($alice, $run['id'], fail: true);
        $this->playUnit($alice, $run['id']);
        $this->stop($alice, $run['id']);

        $items = $this->review($alice, $run['id']);
        self::assertSame(
            [[1, 'repertoire_unit', 'fail', 1], [2, 'repertoire_unit', 'ok', 2]],
            array_map(static fn (array $item): array => [$item['index'], $item['type'], $item['status'], $item['data']['rank']], $items),
        );
        $failed = $items[0]['data'];
        self::assertSame($this->fenOf($this->positionAfter($repertoire, 'e4')), $failed['startFen'], 'after 1.e4, where the segment starts');
        self::assertSame(['c5', 'Nf3', 'd6', 'd4'], $failed['moves']);
        self::assertSame(['segment', 'white', 'Test', '1…c5', 2], [$failed['unit'], $failed['orientation'], $failed['repertoireName'], $failed['label']['move'], $failed['firstErrorPly']], 'wrong on its first question, 2.Nf3');
        self::assertNull($items[1]['data']['firstErrorPly']);
    }

    public function testALinePutsItsSegmentsEndToEndFromTheInitialPosition(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->openBook($alice);
        $run = $this->start($alice, ['repertoireIds' => [$repertoire->getId()->toRfc4122()], 'unit' => 'line']);
        $this->playUnit($alice, $run['id'], fail: true);
        $this->stop($alice, $run['id']);

        $items = $this->review($alice, $run['id']);
        self::assertCount(1, $items, 'one item per line, however many segments');
        $line = $items[0]['data'];
        self::assertSame(['line', 'fail', Rules::initial()->normalizedFen()], [$line['unit'], $items[0]['status'], $line['startFen']]);
        $presented = $this->presentations("status <> 'in_progress'");
        self::assertSame(array_merge(...array_column($presented, 'moves')), $line['moves']);
    }

    public function testUnitsPresentedBeforeTheStartWasKeptHaveNone(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->repertoire($alice, self::ITALIAN);
        $run = $this->start($alice, ['repertoireIds' => [$repertoire->getId()->toRfc4122()], 'segmentIds' => [$this->segmentOf($repertoire, 'e4', 'c5')]]);
        $this->playUnit($alice, $run['id']);
        $this->stop($alice, $run['id']);
        $this->connection()->executeStatement('UPDATE repertoire_presentation SET start_fen = NULL');

        self::assertNull($this->review($alice, $run['id'])[0]['data']['startFen']);
    }

    public function testAUnitCutByTheEndBeforeAnyMistakeIsLeftOut(): void
    {
        $alice = $this->createUser('alice@example.com');
        $repertoire = $this->repertoire($alice, self::ITALIAN);
        $run = $this->start($alice, ['repertoireIds' => [$repertoire->getId()->toRfc4122()], 'segmentIds' => [$this->segmentOf($repertoire, 'e4', 'c5')]]);
        $this->next($alice, $run['id']);
        $this->stop($alice, $run['id']);

        self::assertSame([], $this->review($alice, $run['id']));
    }

    /**
     * @return list<UnitJson>
     */
    private function review(User $user, string $runId): array
    {
        $response = $this->api('GET', '/api/training/runs/'.$runId.'/review', $user);
        self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());

        /** @var array{items: list<UnitJson>} $review */
        $review = $this->json($response);

        return $review['items'];
    }
}
