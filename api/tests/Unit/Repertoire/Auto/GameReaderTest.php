<?php

declare(strict_types=1);

namespace App\Tests\Unit\Repertoire\Auto;

use App\Enum\Chess\EndgameType;
use App\Enum\Repertoire\Color;
use App\Repertoire\Auto\GameReader;
use App\Repertoire\Auto\GameRecord;
use App\Tests\Unit\Chess\Endgame\EndgameTest;
use PHPUnit\Framework\TestCase;

final class GameReaderTest extends TestCase
{
    public function testAGameIsReadFromTheUsersPointOfView(): void
    {
        $game = self::read(self::games()[0]);

        self::assertNotNull($game);
        self::assertSame(['game0001', Color::White, 1, 1790000400000, 'blitz', 'Pion_Furtif', 1795], [$game->id, $game->color, $game->result, $game->playedAt, $game->perf, $game->opponent, $game->opponentRating]);
        self::assertSame(['Italian Game', 'Italian Game: Giuoco Pianissimo', 'white:Italian Game'], [$game->family, $game->opening, $game->familyKey()]);
        self::assertSame(['e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1c4', 'f8c5', 'c2c3', 'g8f6', 'd2d3', 'd7d6'], $game->moves);
        // Clock before the move (own previous clock) - clock after it + increment; none on the first move.
        self::assertSame([null, null, 400, null, 600, null, 1600, null, 300, null], $game->thinks);
        self::assertTrue($game->analysed);
        self::assertSame([[6, GameRecord::MISTAKE, 'b1c3']], $game->judgments);
        self::assertTrue($game->errorAt(6));
        self::assertSame('b1c3', $game->bestAt(6));
        self::assertFalse($game->errorAt(4));
        self::assertSame([1842, null], [$game->rating, $game->endgameType], 'ten plies: no endgame');
    }

    public function testTheWholeGameIsFollowedToItsEndgameFromTheUsersPointOfView(): void
    {
        // Alice has Black; the engine says -2.5 for White after the 57th ply: +2.5 for her.
        $analysis = array_fill(0, 85, ['eval' => 0]);
        $analysis[56] = ['eval' => -250];
        $long = [
            ...self::games()[1],
            'id' => 'game0006',
            'moves' => EndgameTest::RUY_LOPEZ,
            'winner' => null,
            'status' => 'draw',
            'opening' => ['eco' => 'C95', 'name' => 'Ruy Lopez: Closed, Breyer Defense', 'ply' => 18],
            'clocks' => [],
            'analysis' => $analysis,
        ];
        $game = GameReader::read($long, 'alicelichess', 6);

        self::assertNotNull($game);
        self::assertSame(['e2e4', 'e7e5', 'g1f3', 'b8c6', 'f1b5', 'a7a6'], $game->moves, 'the repertoire plies only');
        self::assertSame([0, 1840, EndgameType::RookMinor, 57, 0, 250], [$game->result, $game->rating, $game->endgameType, $game->endgamePly, $game->endgameBalance, $game->endgameEval]);

        // A mate found for White: lost for her.
        $analysis[56] = ['mate' => 4];
        self::assertSame(-GameRecord::MATE_CP, GameReader::read([...$long, 'analysis' => $analysis], 'alicelichess', 6)?->endgameEval);
        // Not analysed: the material only.
        unset($long['analysis']);
        $game = GameReader::read($long, 'alicelichess', 6);
        self::assertNotNull($game);
        self::assertSame([EndgameType::RookMinor, 0, null], [$game->endgameType, $game->endgameBalance, $game->endgameEval]);
        self::assertEquals([$game], GameRecord::listFromJson(GameRecord::listToJson([$game])));
    }

    public function testBuildsStoredBeforeTheEndgameWasKeptAreStillRead(): void
    {
        $game = self::read(self::games()[0]);
        self::assertNotNull($game);
        $stored = $game->toArray();
        unset($stored['ur'], $stored['e']);

        $read = GameRecord::listFromJson((string) json_encode([$stored]))[0];
        self::assertSame([null, null, null], [$read->rating, $read->endgameType, $read->endgameEval]);
    }

    public function testALossWithBlackWithoutAnalysis(): void
    {
        $game = self::read(self::games()[1]);

        self::assertNotNull($game);
        self::assertSame([Color::Black, -1, 'Sicilian Defense', false, []], [$game->color, $game->result, $game->family, $game->analysed, $game->judgments]);
        self::assertSame([null, null, null, 500, null, 1900], $game->thinks, 'the black plies only');
    }

    public function testADrawAgainstTheComputer(): void
    {
        $game = self::read(self::games()[4]);

        self::assertNotNull($game);
        self::assertSame([0, 'Stockfish 3', null], [$game->result, $game->opponent, $game->opponentRating]);
        self::assertSame([null, null, null, null, null], $game->thinks, 'no clocks');
    }

    public function testOtherVariantsUnfinishedGamesAndOtherPlayersAreLeftOut(): void
    {
        $games = self::games();
        self::assertNull(self::read($games[2]), 'chess960');
        self::assertNull(self::read($games[3]), 'aborted');
        self::assertNull(GameReader::read($games[0], 'somebody_else', 40), 'not the user\'s game');
        $fromPosition = [...$games[0], 'initialFen' => 'rnbqkbnr/pppppppp/8/8/8/8/PPPPPPPP/RNBQKBNR w KQkq - 0 1'];
        self::assertNull(self::read($fromPosition));
        $noOpening = $games[0];
        unset($noOpening['opening']);
        self::assertNull(self::read($noOpening));
    }

    public function testMovesStopAtThePlyLimitAndAtAnIllegalMove(): void
    {
        self::assertSame(['e2e4', 'e7e5', 'g1f3', 'b8c6'], GameReader::read(self::games()[0], 'alicelichess', 4)?->moves);
        $illegal = [...self::games()[0], 'moves' => 'e4 e5 Nf3 Ke6 Bc4'];
        self::assertSame(['e2e4', 'e7e5', 'g1f3'], self::read($illegal)?->moves);
        self::assertNull(self::read([...self::games()[0], 'moves' => 'e4 Ke2']), 'fewer than two moves left');
    }

    public function testRecordsSurviveTheirJson(): void
    {
        $games = array_values(array_filter(array_map(self::read(...), self::games())));

        self::assertCount(3, $games);
        self::assertEquals($games, GameRecord::listFromJson(GameRecord::listToJson($games)));
    }

    public function testStoredDataIsChecked(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        GameRecord::listFromJson('[{"id": "x", "c": "green"}]');
    }

    /**
     * @param array<mixed> $game
     */
    private static function read(array $game): ?GameRecord
    {
        return GameReader::read($game, 'alicelichess', 40);
    }

    /**
     * @return list<array<mixed>>
     */
    private static function games(): array
    {
        $games = [];
        foreach (file(__DIR__.'/../../../Fixtures/Repertoire/lichess-games.ndjson', \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $game = json_decode($line, true);
            if (\is_array($game)) {
                $games[] = $game;
            }
        }

        return $games;
    }
}
