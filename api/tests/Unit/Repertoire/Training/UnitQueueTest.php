<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Training;

use App\Repertoire\Training\UnitQueue;
use App\Repertoire\Training\UnitStanding;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class UnitQueueTest extends TestCase
{
    public function testOverdueThenFailedThenTheLeastPresented(): void
    {
        $units = [
            self::unit('often', presentations: 9),
            self::unit('new'),
            self::unit('failed', lastFailed: true, presentations: 4),
            self::unit('overdue-late', overdue: '2026-10-01 09:00'),
            self::unit('twice', presentations: 2),
            self::unit('overdue-early', overdue: '2026-09-20 09:00', lastFailed: true),
        ];

        foreach ([1, 2, 3] as $seed) {
            self::assertSame(
                ['overdue-early', 'overdue-late', 'failed', 'new', 'twice', 'often'],
                self::keys((new UnitQueue(new Randomizer(new Mt19937($seed))))->order($units)),
            );
        }
    }

    public function testTiesAreDrawnAtRandom(): void
    {
        $units = array_map(static fn (int $i): UnitStanding => self::unit('u'.$i), range(1, 8));
        $orders = [];
        foreach (range(1, 20) as $seed) {
            $orders[implode(',', self::keys((new UnitQueue(new Randomizer(new Mt19937($seed))))->order($units)))] = true;
        }

        self::assertGreaterThan(10, \count($orders));
    }

    public function testANewRoundDoesNotStartWithTheUnitJustPlayed(): void
    {
        $units = [self::unit('a', lastFailed: true), self::unit('b')];

        self::assertSame(['b', 'a'], self::keys((new UnitQueue())->order($units, 'a')));
        self::assertSame(['a'], self::keys((new UnitQueue())->order([self::unit('a')], 'a')), 'alone, it comes back');
    }

    public function testAFailedSegmentComesBackAfterThreeOthersAndALineAtOnce(): void
    {
        self::assertSame(3, UnitQueue::retryIndex(10, false));
        self::assertSame(1, UnitQueue::retryIndex(1, false), 'at the end of a shorter queue');
        self::assertSame(0, UnitQueue::retryIndex(0, false));
        self::assertSame(0, UnitQueue::retryIndex(10, true));
    }

    private static function unit(string $key, ?string $overdue = null, bool $lastFailed = false, int $presentations = 0): UnitStanding
    {
        return new UnitStanding('r', $key, null === $overdue ? null : new \DateTimeImmutable($overdue), $lastFailed, $presentations);
    }

    /**
     * @param list<UnitStanding> $units
     *
     * @return list<string>
     */
    private static function keys(array $units): array
    {
        return array_map(static fn (UnitStanding $unit): string => $unit->key, $units);
    }
}
