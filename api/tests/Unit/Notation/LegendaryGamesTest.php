<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notation;

use App\Notation\Games\LegendaryGames;
use PHPUnit\Framework\TestCase;

/**
 * The legendary games (docs/NOTATION.md): every game of data/legendary-games.json replays to its
 * end from its PGN.
 */
final class LegendaryGamesTest extends TestCase
{
    public function testEveryGameReplays(): void
    {
        $path = __DIR__.'/../../../data/legendary-games.json';
        $rows = json_decode((string) file_get_contents($path), true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($rows);
        $games = (new LegendaryGames($path))->all();

        self::assertCount(\count($rows), $games, 'No game left out.');
        $opera = $games[1] ?? null;
        self::assertNotNull($opera);
        self::assertSame('Paul Morphy', $opera->white);
        self::assertCount(33, $opera->moves);
        self::assertSame(['e2e4', 'e7e5', 'g1f3'], \array_slice($opera->moves, 0, 3));
        self::assertSame('d1d8', $opera->moves[32], '17. Rd8#.');
        self::assertSame('Paul Morphy – Duc de Brunswick / Comte Isouad de Vauvenargues, Paris 1858', $opera->label());
    }

    public function testAPgnThatDoesNotReplay(): void
    {
        self::assertNull(LegendaryGames::moves('1. e4 e5 2. Ke3 Nc6'));
        self::assertSame(['e2e4', 'e7e5'], LegendaryGames::moves("[Event \"?\"]\n\n1. e4 e5 *"));
    }
}
