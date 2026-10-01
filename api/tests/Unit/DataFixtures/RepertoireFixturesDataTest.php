<?php

declare(strict_types=1);

namespace App\Tests\Unit\DataFixtures;

use App\Enum\Repertoire\Color;
use App\Repertoire\Import\ImportPlanner;
use App\Repertoire\Import\PgnAnalyzer;
use App\Repertoire\Import\Target;
use App\Repertoire\Limits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The demo repertoires (App\DataFixtures\Repertoire) import cleanly, with what they are meant to
 * show.
 */
final class RepertoireFixturesDataTest extends TestCase
{
    private const DATA = __DIR__.'/../../../src/DataFixtures/Repertoire/data/';

    /**
     * @return iterable<string, array{string, Color, int, int}>
     */
    public static function files(): iterable
    {
        yield 'white 1.e4' => ['white-e4.pgn', Color::White, 6, 0];
        yield 'black against 1.d4' => ['black-d4.pgn', Color::Black, 4, 0]; // 2.Nf3 joins the main line: no end of its own
    }

    #[DataProvider('files')]
    public function testTheDemoRepertoiresImportWithoutWarning(string $file, Color $color, int $lines, int $conflicts): void
    {
        $tree = (new PgnAnalyzer(new Limits()))->analyze((string) file_get_contents(self::DATA.$file));
        $plan = (new ImportPlanner())->plan($tree, $color, Target::empty());

        self::assertSame($color->value, $tree->color);
        self::assertSame([], $plan->warnings);
        self::assertSame($lines, $plan->lines);
        self::assertCount($conflicts, $plan->conflicts, 'one prepared move per position');
    }

    public function testTheBlackRepertoireHasATransposition(): void
    {
        $tree = (new PgnAnalyzer(new Limits()))->analyze((string) file_get_contents(self::DATA.'black-d4.pgn'));

        $reached = array_count_values(array_column($tree->edges, 'to'));
        self::assertSame([2], array_values(array_filter($reached, static fn (int $n): bool => $n > 1)), 'one position reached by two move orders');
    }
}
