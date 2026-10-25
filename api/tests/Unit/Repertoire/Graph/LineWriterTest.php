<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Graph;

use App\Chess\Rules;
use App\Repertoire\Exception\IllegalMoveException;
use App\Repertoire\Exception\RepeatedPositionException;
use App\Repertoire\Graph\LineWriter;
use PHPUnit\Framework\TestCase;

final class LineWriterTest extends TestCase
{
    public function testALineBecomesATreeWithTheUsersMovesChosen(): void
    {
        $initial = Rules::initial()->normalizedFen();
        [$tree, $choices] = LineWriter::line($initial, ['e2e4', 'e7e5', 'g1f3'], 'w');

        self::assertSame(['e4', 'e5', 'Nf3'], array_column($tree->edges, 'san'));
        self::assertSame($initial, $tree->edges[0]['from']);
        self::assertSame($tree->edges[0]['to'], $tree->edges[1]['from']);
        self::assertSame([['game' => 1, 'fen' => $initial]], $tree->starts);
        self::assertCount(4, $tree->positions);
        self::assertSame([$initial => 'e2e4', $tree->edges[2]['from'] => 'g1f3'], $choices);

        [, $black] = LineWriter::line($initial, ['e2e4', 'e7e5', 'g1f3'], 'b');
        self::assertSame([$tree->edges[1]['from'] => 'e7e5'], $black);
    }

    public function testAnIllegalMoveIsRefused(): void
    {
        $this->expectException(IllegalMoveException::class);
        LineWriter::line(Rules::initial()->normalizedFen(), ['e2e4', 'e2e4'], 'w');
    }

    public function testALineBackToOneOfItsPositionsIsRefused(): void
    {
        $this->expectException(RepeatedPositionException::class);
        LineWriter::line(Rules::initial()->normalizedFen(), ['g1f3', 'g8f6', 'f3g1', 'f6g8'], 'w');
    }
}
