<?php

declare(strict_types=1);

namespace App\Tests\Unit\Puzzle\Import;

use App\Puzzle\Import\InvalidLineException;
use App\Puzzle\Import\LineParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LineParserTest extends TestCase
{
    /** Lichess puzzle 000Zo, as the split export lists it (no opening tags, no daily date). */
    private const LINE = ['000Zo', '4r3/1k6/pp3r2/1b2P2p/3R1p2/P1R2P2/1P4PP/6K1 w - - 0 35', 'e5f6 e8e1 g1f2 e1f1', '1340', '75', '86', '620', 'endgame mate mateIn2 short', 'https://lichess.org/n8Ff742v#68', ''];

    public function testAValidLineIsTyped(): void
    {
        $row = LineParser::parse(self::LINE);

        self::assertSame('000Zo', $row->lichessId);
        self::assertSame('4r3/1k6/pp3r2/1b2P2p/3R1p2/P1R2P2/1P4PP/6K1 w - - 0 35', $row->fen);
        self::assertSame('e5f6 e8e1 g1f2 e1f1', $row->moves);
        self::assertSame(1340, $row->rating);
        self::assertSame(75, $row->ratingDeviation);
        self::assertSame(86, $row->popularity);
        self::assertSame(620, $row->nbPlays);
        self::assertSame(['endgame', 'mate', 'mateIn2', 'short'], $row->themes);
        self::assertSame('https://lichess.org/n8Ff742v#68', $row->gameUrl);
        self::assertNull($row->openingTags);
        self::assertNull($row->dailyDate);
        self::assertTrue($row->isSelectable());
    }

    public function testOptionalColumnsAreRead(): void
    {
        $line = self::LINE;
        $line[5] = '-12';
        $line[9] = 'Kings_Pawn_Game Kings_Pawn_Game_Leonardis_Variation';
        $line[10] = '2024-02-29';

        $row = LineParser::parse($line);

        self::assertSame(-12, $row->popularity);
        self::assertSame(['Kings_Pawn_Game', 'Kings_Pawn_Game_Leonardis_Variation'], $row->openingTags);
        self::assertSame('2024-02-29', $row->dailyDate);
        self::assertFalse($row->isSelectable());
    }

    public function testNineColumnsAreEnough(): void
    {
        self::assertNull(LineParser::parse(\array_slice(self::LINE, 0, 9))->openingTags);
    }

    /**
     * @return iterable<string, array{int, string}>
     */
    public static function invalidFields(): iterable
    {
        yield 'id too short' => [0, '000Z'];
        yield 'id with a dash' => [0, '00-Zo'];
        yield 'no side to move' => [1, '4r3/1k6/pp3r2/1b2P2p/3R1p2/P1R2P2/1P4PP/6K1 - - 0 35'];
        yield 'FEN too long' => [1, str_repeat('8/', 50).'8 w - - 0 1'];   // 111 characters, 92 at most
        yield 'one move only' => [2, 'e5f6'];
        yield 'SAN move' => [2, 'e5f6 Re1'];
        yield 'rating not a number' => [3, '1340.5'];
        yield 'popularity over 100' => [5, '101'];
        yield 'negative play count' => [6, '-1'];
        yield 'theme with a quote' => [7, 'mate "x"'];
        yield 'not a Lichess URL' => [8, 'https://example.org/n8Ff742v'];
    }

    #[DataProvider('invalidFields')]
    public function testAnInvalidFieldRejectsTheLine(int $index, string $value): void
    {
        $line = self::LINE;
        $line[$index] = $value;

        $this->expectException(InvalidLineException::class);
        LineParser::parse(array_values($line));
    }

    public function testTooFewColumnsRejectTheLine(): void
    {
        $this->expectException(InvalidLineException::class);
        LineParser::parse(\array_slice(self::LINE, 0, 8));
    }
}
