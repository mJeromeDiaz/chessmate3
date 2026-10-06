<?php

declare(strict_types=1);

namespace App\Tests\Functional\Puzzle;

use App\Puzzle\Selection\RebuildLock;
use App\State\Puzzle\PuzzleMaintenanceHttpException;

/**
 * While the selection index is rebuilt in place (docs/PUZZLE_IMPORT.md), themed draws answer 503
 * with `X-Puzzle-Maintenance` and draws without theme go on. The lock is taken here as
 * {@see \App\Puzzle\Selection\SelectionRebuilder::rebuildAll()} takes it, whose DDL cannot run in a
 * test transaction.
 */
final class SelectionMaintenanceTest extends PuzzleWebTestCase
{
    private RebuildLock $lock;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lock = new RebuildLock(self::getContainer()->get('doctrine.dbal.catalog_connection'));
        self::assertTrue($this->lock->acquire());
    }

    protected function tearDown(): void
    {
        // A named lock is not transactional: DAMA's rollback would leave it held.
        $this->lock->release();
        parent::tearDown();
    }

    public function testAThemedPuzzleWaitsForTheRebuild(): void
    {
        $user = $this->createUser('maintenance@example.com');

        $response = $this->api('POST', '/api/puzzles/attempts', $user, ['themes' => ['mateIn1']]);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('rebuilding', $response->headers->get(PuzzleMaintenanceHttpException::HEADER));
        self::assertSame('60', $response->headers->get('Retry-After'));
    }

    public function testAPuzzleWithoutThemeIsStillServed(): void
    {
        $user = $this->createUser('maintenance@example.com');

        self::assertSame(201, $this->api('POST', '/api/puzzles/attempts', $user, [])->getStatusCode());
    }

    public function testAThemedWoodpeckerSetWaitsForTheRebuild(): void
    {
        $user = $this->createUser('maintenance@example.com');

        $response = $this->api('POST', '/api/woodpecker/sets', $user, [
            'name' => 'Endgames',
            'puzzleCount' => 5,
            'ratingMin' => 400,
            'ratingMax' => 3200,
            'cycleCount' => 3,
            'firstCycleDays' => 4,
            'themes' => ['endgame'],
        ]);

        self::assertSame(503, $response->getStatusCode());
        self::assertSame('rebuilding', $response->headers->get(PuzzleMaintenanceHttpException::HEADER));
    }

    public function testTheLockIsReleasedAfterwards(): void
    {
        $this->lock->release();

        self::assertFalse($this->lock->isHeld());
        self::assertTrue($this->lock->acquire());
    }
}
