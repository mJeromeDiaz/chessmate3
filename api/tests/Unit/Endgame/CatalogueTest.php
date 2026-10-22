<?php

declare(strict_types=1);

namespace App\Tests\Unit\Endgame;

use App\Chess\Rules;
use App\Endgame\Drawing\VariantDrawer;
use App\Endgame\Lesson\Catalogue;
use App\Endgame\Tablebase\Tablebase;
use App\Enum\Endgame\Family;
use App\Enum\Endgame\Goal;
use App\Enum\Endgame\Result;
use PHPUnit\Framework\TestCase;

final class CatalogueTest extends TestCase
{
    public function testEveryTextbookPositionIsPlayableAndInTheTablebase(): void
    {
        foreach (Catalogue::all() as $key => $lesson) {
            self::assertSame($key, $lesson->key);
            self::assertMatchesRegularExpression('/^[a-z0-9-]{1,32}$/', $key);
            $rules = Rules::fromFen($lesson->textbook);
            self::assertNotSame([], $rules->legalMoves(), $key);
            self::assertTrue(Tablebase::covers($rules->normalizedFen()), $key);
            self::assertNotSame(Result::Loss, $lesson->result, $key);
            self::assertSame(Goal::Hold === $lesson->goal, $lesson->holdMoves > 0, "$key: hold lessons, and only them, have a number of moves");
            self::assertSame(Goal::Hold === $lesson->goal, Result::Draw === $lesson->result, $key);
            self::assertSame($lesson->textbook, (new VariantDrawer())->variants($lesson)[0], "$key: the textbook position comes first");
        }
    }

    public function testEveryThemeHasLessons(): void
    {
        foreach (Family::cases() as $family) {
            self::assertGreaterThanOrEqual(3, \count(Catalogue::ofFamily($family)), $family->value);
        }
        self::assertNull(Catalogue::get('breakthrough'), 'Eight pieces: out of the tablebase, removed.');
    }
}
