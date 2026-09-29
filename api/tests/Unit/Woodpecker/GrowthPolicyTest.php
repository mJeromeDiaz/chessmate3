<?php

declare(strict_types=1);

namespace App\Tests\Unit\Woodpecker;

use App\Woodpecker\Light\GrowthPolicy;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GrowthPolicyTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int}>
     */
    public static function cases(): iterable
    {
        yield 'enough unseen puzzles' => [100, 5, 0];
        yield 'running out' => [100, 4, 20];
        yield 'none left' => [100, 0, 20];
        yield 'close to the maximum' => [1490, 0, 10];
        yield 'at the maximum' => [1500, 0, 0];
    }

    #[DataProvider('cases')]
    public function testBatch(int $size, int $unseen, int $expected): void
    {
        self::assertSame($expected, (new GrowthPolicy(100, 20, 5, 1500))->batchFor($size, $unseen));
    }
}
