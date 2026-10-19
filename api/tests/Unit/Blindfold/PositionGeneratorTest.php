<?php

declare(strict_types=1);

namespace App\Tests\Unit\Blindfold;

use App\Blindfold\Recall\Placement;
use App\Blindfold\Recall\PositionGenerator;
use App\Blindfold\Recall\RecallRules;
use App\Chess\Rules;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Generated positions of the first twelve recall levels (docs/BLINDFOLD_RECALL.md): the level's
 * pieces exactly, legal, nobody in check, no pawn on the first or last rank.
 */
final class PositionGeneratorTest extends TestCase
{
    private const SEEDS = 40;

    public function testEachGeneratedLevelHasItsPiecesInALegalPosition(): void
    {
        foreach (range(1, 12) as $level) {
            $tokens = RecallRules::level($level)['pieces'];
            for ($seed = 0; $seed < self::SEEDS; ++$seed) {
                $fen = (new PositionGenerator(new Randomizer(new Mt19937($seed))))->generate($tokens);
                $squares = Placement::squares($fen);
                self::assertStringEndsWith(' w - - 0 1', $fen);
                self::assertSame(self::kinds($tokens), self::kinds(self::tokensOf($squares)), "level $level, seed $seed: $fen");
                self::assertFalse(Rules::fromFen($fen)->inCheck(), $fen);
                foreach ($squares as $square => $piece) {
                    if ('p' === strtolower($piece)) {
                        self::assertContains($square[1], ['2', '3', '4', '5', '6', '7'], $fen);
                    }
                }
            }
        }
    }

    public function testFixedSidesAreKept(): void
    {
        for ($seed = 0; $seed < self::SEEDS; ++$seed) {
            $squares = Placement::squares((new PositionGenerator(new Randomizer(new Mt19937($seed))))->generate(RecallRules::level(3)['pieces']));
            $pieces = array_values($squares);
            sort($pieces);
            self::assertSame(['K', 'P', 'P', 'P', 'k', 'p'], $pieces);
            self::assertStringContainsString('B', (new PositionGenerator(new Randomizer(new Mt19937($seed))))->generate(RecallRules::level(4)['pieces']));
        }
    }

    public function testTwoBishopsOfOneSideStandOnBothColours(): void
    {
        for ($seed = 0; $seed < self::SEEDS; ++$seed) {
            $squares = Placement::squares((new PositionGenerator(new Randomizer(new Mt19937($seed))))->generate(RecallRules::level(6)['pieces']));
            $bishops = array_keys(array_filter($squares, static fn (string $piece): bool => 'b' === strtolower($piece)));
            self::assertCount(2, $bishops);
            self::assertSame($squares[$bishops[0]], $squares[$bishops[1]], 'one side');
            $colour = static fn (string $square): int => (\ord($square[0]) + (int) $square[1]) % 2;
            self::assertNotSame($colour($bishops[0]), $colour($bishops[1]));
        }
    }

    /**
     * The kinds of pieces, sides aside, kings included: "KkBPPP" style, sorted.
     *
     * @param list<string> $tokens
     */
    private static function kinds(array $tokens): string
    {
        $letters = str_split(strtoupper(str_replace('?', '', implode('', $tokens))));
        sort($letters);

        return implode('', $letters);
    }

    /**
     * @param array<string, string> $squares
     *
     * @return list<string> the pieces besides the kings
     */
    private static function tokensOf(array $squares): array
    {
        $pieces = array_values($squares);
        foreach (['K', 'k'] as $king) {
            $at = array_search($king, $pieces, true);
            self::assertIsInt($at, 'both kings');
            unset($pieces[$at]);
        }

        return array_values($pieces);
    }
}
