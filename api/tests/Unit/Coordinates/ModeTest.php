<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coordinates;

use App\Enum\Coordinates\Mode;
use PHPUnit\Framework\TestCase;

/**
 * The modes of a coordinates series (docs/COORDINATES.md, § 2 bis): what each one expects and
 * accepts as an answer; the squares' colours.
 */
final class ModeTest extends TestCase
{
    public function testTheColoursOfTheSquares(): void
    {
        self::assertSame(
            ['dark', 'light', 'light', 'dark', 'light', 'dark'],
            array_map(Mode::colorOf(...), ['a1', 'h1', 'a8', 'h8', 'e4', 'd4']),
        );
    }

    public function testWhatEachModeExpectsAndAccepts(): void
    {
        self::assertSame(['e4', 'e4', 'light'], [Mode::Find->expected('e4'), Mode::Name->expected('e4'), Mode::Color->expected('e4')]);
        self::assertTrue(Mode::Name->accepts('h8'));
        self::assertFalse(Mode::Name->accepts('light'));
        self::assertFalse(Mode::Find->accepts('i9'));
        self::assertTrue(Mode::Color->accepts('dark'));
        self::assertFalse(Mode::Color->accepts('e4'));
    }
}
