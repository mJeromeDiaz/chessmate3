<?php

declare(strict_types=1);

namespace App\Tests\Unit\Dashboard;

use App\Dashboard\Puzzle\ThemeStrengths;
use PHPUnit\Framework\TestCase;

/**
 * Split of the themes into strong and weak ones (docs/DASHBOARD.md).
 */
final class ThemeStrengthsTest extends TestCase
{
    public function testFewThemesAreNeverStrongAndWeakAtOnce(): void
    {
        self::assertSame(['themes' => [], 'strong' => [], 'weak' => []], ThemeStrengths::split([]));

        $one = ThemeStrengths::split([self::theme('fork', 10, 0.5)]);
        self::assertSame(['fork'], self::keys($one['strong']));
        self::assertSame([], $one['weak']);

        $three = ThemeStrengths::split([self::theme('pin', 10, 0.2), self::theme('fork', 10, 0.9), self::theme('skewer', 10, 0.5)]);
        self::assertSame(['fork', 'skewer', 'pin'], self::keys($three['themes']));
        self::assertSame(['fork', 'skewer'], self::keys($three['strong']));
        self::assertSame(['pin'], self::keys($three['weak']));
    }

    public function testAtMostFiveEachTheWeakestFirst(): void
    {
        $themes = [];
        foreach (range(1, 12) as $i) {
            $themes[] = self::theme('t'.$i, 10, $i / 20);
        }
        $split = ThemeStrengths::split($themes);
        self::assertSame(['t12', 't11', 't10', 't9', 't8'], self::keys($split['strong']));
        self::assertSame(['t1', 't2', 't3', 't4', 't5'], self::keys($split['weak']));
        self::assertCount(12, $split['themes']);
    }

    public function testEqualRatesPutTheMostAttemptedFirstOnEachSide(): void
    {
        $split = ThemeStrengths::split([
            self::theme('a', 5, 0.8), self::theme('b', 20, 0.8),
            self::theme('c', 5, 0.1), self::theme('d', 20, 0.1),
        ]);
        self::assertSame(['b', 'a'], self::keys($split['strong']));
        self::assertSame(['d', 'c'], self::keys($split['weak']));
    }

    /**
     * @return array{key: string, attempts: int, successCount: int, successRate: float}
     */
    private static function theme(string $key, int $attempts, float $rate): array
    {
        return ['key' => $key, 'attempts' => $attempts, 'successCount' => (int) round($attempts * $rate), 'successRate' => $rate];
    }

    /**
     * @param list<array{key: string}> $themes
     *
     * @return list<string>
     */
    private static function keys(array $themes): array
    {
        return array_column($themes, 'key');
    }
}
