<?php

declare(strict_types=1);

namespace App\Tests\Unit\Training;

use App\Training\Module\ReviewItem;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ReviewItemTest extends TestCase
{
    /**
     * @return iterable<string, array{bool, int, int, bool, string}>
     */
    public static function attempts(): iterable
    {
        yield 'solved' => [true, 0, 0, false, ReviewItem::OK];
        yield 'hint, no wrong move' => [false, 0, 1, false, ReviewItem::HINT];
        yield 'solution, no wrong move' => [false, 0, 0, true, ReviewItem::HINT];
        yield 'wrong move' => [false, 1, 0, false, ReviewItem::FAIL];
        yield 'wrong move then the solution' => [false, 2, 0, true, ReviewItem::FAIL];
        yield 'given up without a move' => [false, 0, 0, false, ReviewItem::FAIL];
    }

    #[DataProvider('attempts')]
    public function testThePuzzleStatus(bool $solved, int $mistakes, int $hintLevel, bool $solutionShown, string $status): void
    {
        self::assertSame($status, ReviewItem::puzzleStatus($solved, $mistakes, $hintLevel, $solutionShown));
    }
}
