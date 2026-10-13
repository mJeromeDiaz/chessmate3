<?php

declare(strict_types=1);

namespace App\Tests\Unit\Onboarding;

use App\Enum\Onboarding\Level;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LevelTest extends TestCase
{
    /**
     * @return iterable<string, array{int, Level}>
     */
    public static function ratings(): iterable
    {
        yield 'low' => [800, Level::Beginner];
        yield 'just under amateur' => [1199, Level::Beginner];
        yield 'amateur' => [1200, Level::Amateur];
        yield 'confirmed' => [1600, Level::Confirmed];
        yield 'just under expert' => [1999, Level::Confirmed];
        yield 'expert' => [2000, Level::Expert];
        yield 'just under pro' => [2599, Level::Expert];
        yield 'pro' => [2600, Level::Pro];
    }

    #[DataProvider('ratings')]
    public function testTheLevelOfALichessRating(int $rating, Level $level): void
    {
        self::assertSame($level, Level::ofLichessRating($rating));
    }

    public function testTheSeedRatings(): void
    {
        self::assertSame([1000, 1400, 1800, 2200, 2500], array_map(static fn (Level $level): int => $level->seedRating(), Level::cases()));
    }
}
