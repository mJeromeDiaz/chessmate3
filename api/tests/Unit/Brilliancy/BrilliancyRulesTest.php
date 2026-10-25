<?php

declare(strict_types=1);

namespace App\Tests\Unit\Brilliancy;

use App\Brilliancy\BrilliancyRules;
use App\DataFixtures\Puzzle\SamplePuzzles;
use App\Entity\Catalog\Puzzle;
use App\Enum\Brilliancy\Kind;
use PHPUnit\Framework\TestCase;

/**
 * What a puzzle gives to « Brillant ou Blunder » (docs/BRILLIANCY.md, § 2), on the sample puzzles.
 */
final class BrilliancyRulesTest extends TestCase
{
    public function testThePlayersSacrificeIsBrilliant(): void
    {
        // After c5c6, Rxh4 (a rook for a pawn: gxh4), then Qg2#.
        self::assertSame([Kind::Brilliant], self::kindsOf('GlnnF'));
        // After Rh4, Qe1+ (a queen for a rook: Rxe1), then Rxe1#.
        self::assertSame([Kind::Brilliant], self::kindsOf('Glo3V'));
    }

    public function testTheOpponentsSacrificeIsABlunder(): void
    {
        // Ne5 leaves the knight to Nxe5: the puzzle punishes it (Rd8+).
        self::assertSame([Kind::Blunder], self::kindsOf('Glo2A'));
    }

    public function testAPlainFindOrNoSacrificeGivesNothing(): void
    {
        $puzzle = self::sample('GlnnF');
        self::assertSame([], BrilliancyRules::kinds($puzzle->getFen(), explode(' ', $puzzle->getMoves()), ['mateIn1']));
        // Qxf2+ with the f-file guarded: the king cannot take, nothing is given.
        self::assertSame([], self::kindsOf('GlngE'));
        self::assertSame([], BrilliancyRules::kinds($puzzle->getFen(), ['c5c6'], []), 'one move only');
        self::assertSame([], BrilliancyRules::kinds($puzzle->getFen(), ['a1a1', 'b2b3'], []), 'moves that do not play');
    }

    public function testTheDrawWidensAroundTheRating(): void
    {
        self::assertSame([[1200, 1800], [900, 2100], [0, 5500]], BrilliancyRules::ranges(1500));
        self::assertSame([60, 120, 300, 600], BrilliancyRules::budgets());
        self::assertTrue(BrilliancyRules::allows(120));
        self::assertFalse(BrilliancyRules::allows(180));
    }

    /**
     * @return list<Kind>
     */
    private static function kindsOf(string $lichessId): array
    {
        $puzzle = self::sample($lichessId);

        return BrilliancyRules::kinds($puzzle->getFen(), explode(' ', $puzzle->getMoves()), $puzzle->getThemes());
    }

    private static function sample(string $lichessId): Puzzle
    {
        foreach (SamplePuzzles::create() as $puzzle) {
            if ($lichessId === $puzzle->getLichessId()) {
                return $puzzle;
            }
        }
        self::fail('No sample puzzle '.$lichessId.'.');
    }
}
