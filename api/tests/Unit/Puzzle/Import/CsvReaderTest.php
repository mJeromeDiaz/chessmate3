<?php

declare(strict_types=1);

namespace App\Tests\Unit\Puzzle\Import;

use App\Puzzle\Import\CsvReader;
use PHPUnit\Framework\TestCase;

final class CsvReaderTest extends TestCase
{
    private const ZO = '000Zo,4r3/1k6/pp3r2/1b2P2p/3R1p2/P1R2P2/1P4PP/6K1 w - - 0 35,e5f6 e8e1 g1f2 e1f1,1340,75,86,620,endgame mate mateIn2 short,https://lichess.org/n8Ff742v#68,';
    private const AY = '000aY,r4rk1/pp3ppp/2n1b3/q1pp2B1/8/P1Q2NP1/1PP1PP1P/2KR3R w - - 0 15,g5e7 a5c3 b2c3 c6e7,1428,79,73,505,advantage master middlegame short,https://lichess.org/iihZGl6t#28,Benoni_Defense Benoni_Defense_Benoni-Indian_Defense';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/csv-reader-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob($this->dir.'/*') ?: []);
        rmdir($this->dir);
    }

    public function testASplitFileWithoutHeaderAndWithCrlfIsRead(): void
    {
        $file = $this->write('part-1.csv', self::ZO."\r\n".self::AY."\r\n");

        $rows = iterator_to_array((new CsvReader())->rows($file, static fn () => self::fail('No invalid line expected')));

        self::assertSame([1, 2], array_keys($rows));
        self::assertSame('000Zo', $rows[1]->lichessId);
        self::assertNull($rows[1]->openingTags);
        self::assertSame('https://lichess.org/n8Ff742v#68', $rows[1]->gameUrl);
        self::assertSame(['Benoni_Defense', 'Benoni_Defense_Benoni-Indian_Defense'], $rows[2]->openingTags);
    }

    public function testATabSeparatedFileWithAHeaderIsRead(): void
    {
        $header = "PuzzleId\tFEN\tMoves\tRating\tRatingDeviation\tPopularity\tNbPlays\tThemes\tGameUrl\tOpeningTags";
        $file = $this->write('tabs.csv', $header."\n".str_replace(',', "\t", self::ZO)."\n");

        $rows = iterator_to_array((new CsvReader())->rows($file, static fn () => self::fail('No invalid line expected')));

        self::assertSame([2], array_keys($rows));
        self::assertSame(['endgame', 'mate', 'mateIn2', 'short'], $rows[2]->themes);
    }

    public function testInvalidLinesAreReportedAndSkipped(): void
    {
        $file = $this->write('bad.csv', self::ZO."\n"."00-Zo,bad\n\n".self::AY."\n");
        $invalid = [];

        $rows = iterator_to_array((new CsvReader())->rows($file, static function (int $line, string $reason) use (&$invalid): void {
            $invalid[$line] = $reason;
        }));

        self::assertSame([1, 4], array_keys($rows));
        self::assertSame([2], array_keys($invalid));
    }

    public function testDirectoriesGiveTheirCsvFilesInNaturalOrder(): void
    {
        $ten = $this->write('part-10.csv', '');
        $two = $this->write('part-2.csv', '');
        $this->write('notes.txt', '');

        self::assertSame([$two, $ten], CsvReader::files([$this->dir]));
        self::assertSame([$ten], CsvReader::files([$ten]));
    }

    private function write(string $name, string $content): string
    {
        file_put_contents($this->dir.'/'.$name, $content);

        return $this->dir.'/'.$name;
    }
}
