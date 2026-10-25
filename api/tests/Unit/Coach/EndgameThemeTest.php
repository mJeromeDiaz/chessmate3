<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coach;

use App\Coach\Session\EndgameTheme;
use App\Enum\Endgame\Family;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class EndgameThemeTest extends TestCase
{
    public function testTheWeakestEndgameGivesItsTheme(): void
    {
        $theme = new EndgameTheme();

        self::assertSame(Family::Pawns, $theme->for('pawn'));
        self::assertSame(Family::Rooks, $theme->for('rook'));
        self::assertSame(Family::Rooks, $theme->for('rook_minor'));
        self::assertSame(Family::Queens, $theme->for('queen'));
        self::assertContains($theme->for('minor'), [Family::Bishops, Family::Knights]);
    }

    public function testWithoutAWeakEndgameAThemeIsDrawn(): void
    {
        $theme = new EndgameTheme(new Randomizer(new Mt19937(7)));
        $drawn = [];
        for ($i = 0; $i < 200; ++$i) {
            $drawn[$theme->for(null)->value] = true;
            $drawn[$theme->for('mixed')->value] = true;
            $drawn[$theme->for('unknown')->value] = true;
        }

        self::assertEqualsCanonicalizing(array_map(static fn (Family $family): string => $family->value, Family::cases()), array_keys($drawn));
    }
}
