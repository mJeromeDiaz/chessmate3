<?php

declare(strict_types=1);

namespace App\Tests\Unit\EarlyAccess;

use App\EarlyAccess\Stats\LichessRating;
use PHPUnit\Framework\TestCase;

final class LichessRatingTest extends TestCase
{
    public function testRapidComesFirst(): void
    {
        self::assertSame(['perf' => 'rapid', 'rating' => 1850], LichessRating::pick(['ratings' => [
            'blitz' => ['rating' => 1700, 'games' => 300, 'provisional' => false],
            'rapid' => ['rating' => 1850, 'games' => 80, 'provisional' => false],
        ]]));
    }

    public function testAProvisionalRatingFallsBackToTheNextPerf(): void
    {
        self::assertSame(['perf' => 'blitz', 'rating' => 1700], LichessRating::pick(['ratings' => [
            'rapid' => ['rating' => 1500, 'games' => 3, 'provisional' => true],
            'blitz' => ['rating' => 1700, 'games' => 300, 'provisional' => false],
        ]]));
        self::assertSame(['perf' => 'classical', 'rating' => 2010], LichessRating::pick(['ratings' => [
            'classical' => ['rating' => 2010, 'games' => 40, 'provisional' => false],
            'bullet' => ['rating' => 2500, 'games' => 900, 'provisional' => false],
        ]]));
    }

    public function testNoUsableRating(): void
    {
        self::assertNull(LichessRating::pick([]));
        self::assertNull(LichessRating::pick(['ratings' => 'nonsense']));
        self::assertNull(LichessRating::pick(['ratings' => ['bullet' => ['rating' => 2500, 'games' => 900, 'provisional' => false]]]));
        self::assertNull(LichessRating::pick(['ratings' => ['rapid' => ['rating' => 1500, 'games' => 3, 'provisional' => true]]]));
    }
}
