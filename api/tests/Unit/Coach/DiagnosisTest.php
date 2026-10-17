<?php

declare(strict_types=1);

namespace App\Tests\Unit\Coach;

use App\Coach\CoachRules;
use App\Coach\Diagnosis\Diagnosis;
use App\Enum\Blindfold\PuzzleLevel;
use App\Enum\Chess\EndgameType;
use App\Enum\Repertoire\Color;
use App\Repertoire\Auto\GameRecord;
use PHPUnit\Framework\TestCase;

/**
 * What the games say (docs/COACH.md): advantages at the endgame (engine, else material), those let
 * slip, openings by colour, the weakest endgame, the levels drawn from the rating.
 */
final class DiagnosisTest extends TestCase
{
    public function testAnAdvantageComesFromTheEngineElseFromTheMaterial(): void
    {
        // Engine +2.5 though level material; material +3 without analysis; engine +1.5 though a piece up.
        self::assertTrue(CoachRules::advantage(self::game(0, EndgameType::Rook, 0, 250)));
        self::assertTrue(CoachRules::advantage(self::game(0, EndgameType::Rook, 3)));
        self::assertFalse(CoachRules::advantage(self::game(0, EndgameType::Rook, 3, 150)));
        self::assertFalse(CoachRules::advantage(self::game(0, EndgameType::Rook, 1)));
        self::assertNull(CoachRules::advantage(self::game(0)));

        self::assertTrue(CoachRules::lostAdvantage(self::game(0, EndgameType::Rook, 2)), 'drawn');
        self::assertTrue(CoachRules::lostAdvantage(self::game(-1, EndgameType::Rook, 2)), 'lost');
        self::assertFalse(CoachRules::lostAdvantage(self::game(1, EndgameType::Rook, 2)), 'converted');
        self::assertFalse(CoachRules::lostAdvantage(self::game(-1, EndgameType::Rook, 0)), 'no advantage');
    }

    public function testTheGamesAreSummedUp(): void
    {
        $games = [
            self::game(1, null, null, null, Color::White, 'Italian Game', 1700),
            self::game(-1, null, null, null, Color::White, 'Italian Game', 1800),
            self::game(0, EndgameType::Rook, 2, null, Color::White, 'London System', 1900),
            self::game(-1, EndgameType::Rook, 0, 300, Color::Black, 'Sicilian Defense', null),
            self::game(1, EndgameType::Rook, 4, null, Color::Black, 'Sicilian Defense', 1750),
            self::game(0, EndgameType::Pawn, 2, null, Color::Black, 'French Defense', 1600),
            self::game(1, EndgameType::Minor, 0, null, Color::Black, 'Caro-Kann Defense', 1650),
            self::game(0, EndgameType::Minor, 0, null, Color::Black, 'Scandinavian Defense', 1720),
        ];
        $diagnosis = Diagnosis::of($games);

        self::assertSame([8, 1, 1720], [$diagnosis['games'], $diagnosis['analysed'], $diagnosis['rating']], 'median of the 7 ratings');
        self::assertSame(['family' => 'Italian Game', 'games' => 2, 'wins' => 1, 'draws' => 0, 'losses' => 1], $diagnosis['white'][0]);
        self::assertSame(['Sicilian Defense', 'Caro-Kann Defense', 'French Defense'], array_column($diagnosis['black'], 'family'), 'three, most played then by name');
        self::assertSame([6, 4, 3], [$diagnosis['endgames']['games'], $diagnosis['endgames']['advantages'], $diagnosis['endgames']['lost']]);
        // Rooks: 2 let slip of 3 advantages; pawns: 1 of 1; minor pieces: none.
        self::assertSame(['rook', 'pawn', 'minor'], array_column($diagnosis['endgames']['types'], 'type'));
        self::assertSame(['type' => 'rook', 'games' => 3, 'wins' => 1, 'draws' => 1, 'losses' => 1, 'advantages' => 3, 'lost' => 2], $diagnosis['weakest']);
    }

    public function testNothingToSayWithoutGamesOrSlips(): void
    {
        $empty = Diagnosis::of([]);
        self::assertSame([0, CoachRules::DEFAULT_RATING, [], [], null], [$empty['games'], $empty['rating'], $empty['white'], $empty['endgames']['types'], $empty['weakest']]);

        $converted = Diagnosis::of([self::game(1, EndgameType::Queen, 5)]);
        self::assertNull($converted['weakest'], 'every advantage converted');
    }

    public function testLevelsFollowTheRating(): void
    {
        self::assertSame([PuzzleLevel::Easy, PuzzleLevel::Medium, PuzzleLevel::Hard], [CoachRules::blindfoldLevel(1399), CoachRules::blindfoldLevel(1400), CoachRules::blindfoldLevel(1900)]);
        self::assertSame([800, 1800, 2600], [CoachRules::evaluationElo(612), CoachRules::evaluationElo(1765), CoachRules::evaluationElo(2900)]);
    }

    private static function game(int $result, ?EndgameType $type = null, ?int $balance = null, ?int $eval = null, Color $color = Color::White, string $family = 'Italian Game', ?int $rating = 1500): GameRecord
    {
        return new GameRecord(uniqid('g', true), $color, $result, 0, 'blitz', null, null, $family, $family, ['e2e4', 'e7e5'], [null, null], [], null !== $eval, $rating, $type, null === $type ? null : 60, $balance, $eval);
    }
}
