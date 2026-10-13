<?php

declare(strict_types=1);

namespace App\Tests\Unit\Puzzle\Opening;

use App\Puzzle\Opening\OpeningFamily;
use PHPUnit\Framework\TestCase;

/**
 * A Lichess opening family as the catalogue tags its puzzles (docs/PUZZLE_IMPORT.md).
 */
final class OpeningFamilyTest extends TestCase
{
    public function testTagsFollowTheCatalogue(): void
    {
        self::assertSame(
            ['Italian_Game', 'Queens_Pawn_Game', 'Caro-Kann_Defense', 'Vant_Kruijs_Opening', 'Kings_Indian_Attack', 'Sicilian_Defense', ''],
            array_map(OpeningFamily::tag(...), ['Italian Game', "Queen's Pawn Game: London System", 'Caro-Kann Defense', "Van't Kruijs Opening", "King's Indian Attack", ' Sicilian  Defense: Najdorf Variation ', '']),
        );
    }
}
