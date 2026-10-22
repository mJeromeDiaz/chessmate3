<?php

declare(strict_types=1);

namespace App\Tests\Unit\Endgame;

use App\Endgame\Drawing\VariantDrawer;
use App\Endgame\Lesson\Catalogue;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

final class VariantDrawerTest extends TestCase
{
    public function testSymmetriesAndShiftsOfTheLucena(): void
    {
        $lucena = Catalogue::get('lucena');
        self::assertNotNull($lucena);

        $variants = (new VariantDrawer())->variants($lucena);

        self::assertSame('1K1k4/1P6/8/8/8/8/r7/2R5 w - - 0 1', $variants[0]);
        self::assertContains('4k1K1/6P1/8/8/8/8/7r/5R2 w - - 0 1', $variants, 'Mirrored a↔h.');
        self::assertContains('2r5/R7/8/8/8/8/1p6/1k1K4 b - - 0 1', $variants, 'Colours swapped: Black wins.');
        self::assertContains('2K1k3/2P5/8/8/8/8/1r6/3R4 w - - 0 1', $variants, 'One file to the right.');
        self::assertContains('5K1k/5P2/8/8/8/8/4r3/6R1 w - - 0 1', $variants, 'Four files to the right.');
        self::assertSame($variants, array_values(array_unique($variants)));
    }

    public function testShiftedPawnsStayOffTheEdgeFiles(): void
    {
        $opposition = Catalogue::get('opposition');
        self::assertNotNull($opposition);

        foreach ((new VariantDrawer())->variants($opposition) as $fen) {
            $files = [];
            foreach (explode('/', explode(' ', $fen)[0]) as $row) {
                $file = 0;
                foreach (str_split($row) as $char) {
                    if (ctype_digit($char)) {
                        $file += (int) $char;
                        continue;
                    }
                    if ('p' === strtolower($char)) {
                        $files[] = $file;
                    }
                    ++$file;
                }
            }
            self::assertSame([], array_intersect($files, [0, 7]), $fen);
        }
    }

    public function testRandomBishopsAreOnBothColours(): void
    {
        $lesson = Catalogue::get('kbbk');
        self::assertNotNull($lesson);
        $randomizer = new Randomizer(new Mt19937(42));
        $drawer = new VariantDrawer();

        $drawn = 0;
        for ($i = 0; $i < 40; ++$i) {
            $fen = $drawer->random($lesson, $randomizer);
            if (null === $fen) {
                continue;
            }
            ++$drawn;
            [$placement, $turn] = explode(' ', $fen);
            self::assertSame('w', $turn);
            $counts = array_count_values(array_filter(str_split($placement), 'ctype_alpha'));
            ksort($counts);
            self::assertSame(['B' => 2, 'K' => 1, 'k' => 1], $counts, $fen);
            $colours = [];
            foreach (explode('/', $placement) as $row => $line) {
                $file = 0;
                foreach (str_split($line) as $char) {
                    if (ctype_digit($char)) {
                        $file += (int) $char;
                        continue;
                    }
                    if ('B' === $char) {
                        $colours[] = (7 - $row + $file) % 2;
                    }
                    ++$file;
                }
            }
            self::assertNotSame($colours[0], $colours[1], $fen);
        }
        self::assertGreaterThan(10, $drawn, 'Many draws are legal (kings apart, the black king not in check).');
    }

    public function testLessonsWithoutMaterialHaveNoRandomPosition(): void
    {
        $lesson = Catalogue::get('philidor');
        self::assertNotNull($lesson);

        self::assertNull((new VariantDrawer())->random($lesson, new Randomizer()));
    }
}
