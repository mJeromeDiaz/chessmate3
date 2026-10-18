<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Auto;

use App\Chess\Rules;
use App\Enum\Repertoire\Color;
use App\Repertoire\Auto\AutoBuildException;
use App\Repertoire\Auto\GameRecord;
use App\Repertoire\Auto\LineTag;
use App\Repertoire\Auto\RepertoireWriter;
use App\Repertoire\Auto\Selection;
use App\Repertoire\Auto\TreeBuilder;
use App\Repertoire\Limits;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LineTagAndSelectionTest extends TestCase
{
    /**
     * @return iterable<string, array{int, int, int, int|null, int|null, string}>
     */
    public static function lines(): iterable
    {
        yield 'losing score' => [10, 3, 1, null, null, LineTag::RISK];
        yield 'good score, bad position' => [10, 7, 0, -120, null, LineTag::RISK];
        yield 'mated' => [10, 7, 0, null, -3, LineTag::RISK];
        yield 'winning, ahead' => [10, 6, 0, 30, null, LineTag::WEAPON];
        yield 'winning, not evaluated' => [10, 6, 0, null, null, LineTag::WEAPON];
        yield 'winning but a little behind' => [10, 6, 2, -20, null, LineTag::SOLID];
        yield 'many draws' => [10, 3, 4, 0, null, LineTag::DRAWS];
        yield 'average' => [10, 4, 2, 10, null, LineTag::SOLID];
    }

    #[DataProvider('lines')]
    public function testTags(int $games, int $wins, int $draws, ?int $cp, ?int $mate, string $tag): void
    {
        self::assertSame($tag, (new LineTag(new Limits()))->of($games, $wins, $draws, $cp, $mate));
    }

    public function testEvaluationsAreTurnedToTheUsersPointOfView(): void
    {
        self::assertSame(['cp' => 40, 'mate' => null], LineTag::forUser(['cp' => 40, 'mate' => null], Color::White));
        self::assertSame(['cp' => -40, 'mate' => null], LineTag::forUser(['cp' => 40, 'mate' => null], Color::Black));
        self::assertSame(['cp' => null, 'mate' => -2], LineTag::forUser(['cp' => null, 'mate' => 2], Color::Black));
        self::assertSame(['cp' => null, 'mate' => null], LineTag::forUser(null, Color::White));
    }

    public function testNamesAreDatedWhenTaken(): void
    {
        $now = new \DateTimeImmutable('2026-10-09 23:30', new \DateTimeZone('Europe/Paris'));

        self::assertSame('Italian Game (auto)', RepertoireWriter::name('Italian Game', ['Mon Italienne'], $now));
        self::assertSame('Italian Game (auto) 09/10/2026', RepertoireWriter::name('Italian Game', ['Italian Game (auto)'], $now));
        self::assertSame(80, mb_strlen(RepertoireWriter::name(str_repeat('x', 100), [str_repeat('x', 62).' (auto)'], $now)));
    }

    public function testATreeBecomesAnImportFromTheInitialPosition(): void
    {
        $games = array_map(
            static fn (int $i): GameRecord => new GameRecord('g'.$i, Color::White, 1, 0, 'blitz', null, null, 'Italian Game', 'Italian Game', ['e2e4', 'e7e5', 'g1f3'], [null, null, null], [], false),
            [1, 2, 3],
        );
        $imported = RepertoireWriter::imported((new TreeBuilder(new Limits()))->build($games, Color::White));

        self::assertSame(3, $imported->games);
        self::assertSame(['e2e4', 'e7e5', 'g1f3'], array_column($imported->edges, 'uci'));
        self::assertSame(['e4', 'e5', 'Nf3'], array_column($imported->edges, 'san'));
        self::assertSame(Rules::initial()->normalizedFen(), $imported->edges[0]['from']);
        self::assertSame($imported->edges[0]['to'], $imported->edges[1]['from']);
        self::assertCount(4, $imported->positions);
    }

    public function testALineStopsBeforeGoingBackToOneOfItsPositions(): void
    {
        $moves = ['g1f3', 'g8f6', 'f3g1', 'f6g8', 'g1f3'];
        $games = array_map(
            static fn (int $i): GameRecord => new GameRecord('g'.$i, Color::White, 0, 0, 'blitz', null, null, 'Zukertort Opening', 'Zukertort Opening', $moves, array_fill(0, 5, null), [], false),
            [1, 2, 3],
        );
        $tree = (new TreeBuilder(new Limits()))->build($games, Color::White);

        self::assertSame(['g1f3 g8f6 f3g1'], array_column($tree->lines, 'path'), '...Ng8 would bring back the initial position');
    }

    public function testASelectionIsCheckedAgainstTheBuild(): void
    {
        $selection = Selection::checked(['white:Italian Game'], ['white:Italian Game' => ['' => 'd2d4', 'e2e4 e7e5' => 'g1f3']], ['white:Italian Game' => ['e2e4 c7c5']], ['white:Italian Game', 'black:Sicilian Defense']);

        self::assertSame(['families' => ['white:Italian Game'], 'choices' => ['white:Italian Game' => ['' => 'd2d4', 'e2e4 e7e5' => 'g1f3']], 'removed' => ['white:Italian Game' => ['e2e4 c7c5']], 'kept' => [], 'suspended' => []], $selection->toArray());
        self::assertEquals($selection, Selection::fromStored($selection->toArray()));
        self::assertEquals(new Selection(), Selection::fromStored(null));
    }

    /**
     * @return iterable<string, array{mixed, mixed, mixed}>
     */
    public static function invalidSelections(): iterable
    {
        yield 'unknown family' => [['white:French Defense'], [], []];
        yield 'not a list' => ['white:Italian Game', [], []];
        yield 'choice of a family not kept' => [['white:Italian Game'], ['black:Sicilian Defense' => ['' => 'e7e5']], []];
        yield 'SAN instead of UCI' => [['white:Italian Game'], ['white:Italian Game' => ['' => 'e4']], []];
        yield 'malformed path' => [['white:Italian Game'], [], ['white:Italian Game' => ['e4 e5']]];
        yield 'empty removed path' => [['white:Italian Game'], [], ['white:Italian Game' => ['']]];
    }

    #[DataProvider('invalidSelections')]
    public function testInvalidSelectionsAreRefused(mixed $families, mixed $choices, mixed $removed): void
    {
        $this->expectExceptionObject(new AutoBuildException(AutoBuildException::INVALID_SELECTION));

        Selection::checked($families, $choices, $removed, ['white:Italian Game', 'black:Sicilian Defense']);
    }
}
