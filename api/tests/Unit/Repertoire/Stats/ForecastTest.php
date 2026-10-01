<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Stats;

use App\Repertoire\Stats\Forecast;
use PHPUnit\Framework\TestCase;

final class ForecastTest extends TestCase
{
    public function testCardsFallDueByLocalDayOverdueOnesToday(): void
    {
        $zone = new \DateTimeZone('Pacific/Auckland'); // UTC+13 in October
        $now = new \DateTimeImmutable('2026-10-01 20:00:00 UTC'); // 2 October, 9:00 local
        $dues = array_map(static fn (string $at): \DateTimeImmutable => new \DateTimeImmutable($at.' UTC'), [
            '2026-09-01 10:00:00', // overdue
            '2026-10-02 10:00:00', // 2 October 23:00 local: today
            '2026-10-02 11:30:00', // 3 October 00:30 local
            '2026-10-08 10:00:00', // 8 October 23:00 local: the last day
        ]);

        self::assertSame('2026-10-09 00:00:00', Forecast::until($now, $zone)->format('Y-m-d H:i:s'));
        self::assertSame([
            ['date' => '2026-10-02', 'due' => 2],
            ['date' => '2026-10-03', 'due' => 1],
            ['date' => '2026-10-04', 'due' => 0],
            ['date' => '2026-10-05', 'due' => 0],
            ['date' => '2026-10-06', 'due' => 0],
            ['date' => '2026-10-07', 'due' => 0],
            ['date' => '2026-10-08', 'due' => 1],
        ], Forecast::days($dues, $now, $zone));
    }
}
