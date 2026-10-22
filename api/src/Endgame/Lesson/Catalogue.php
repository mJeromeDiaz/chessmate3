<?php

declare(strict_types=1);

namespace App\Endgame\Lesson;

use App\Enum\Endgame\Family;
use App\Enum\Endgame\Goal;
use App\Enum\Endgame\Result;

/**
 * The lessons of the endgames module, in the order of the page (docs/ENDGAMES.md, § 3). Each
 * textbook position was checked against the Lichess tablebase (result for the side to move, the
 * player). Keys are stored (pool, attempts): add lessons, never rename one.
 */
final class Catalogue
{
    /** @var array<string, Lesson>|null */
    private static ?array $lessons = null;

    /**
     * @return array<string, Lesson> by key
     */
    public static function all(): array
    {
        if (null === self::$lessons) {
            self::$lessons = [];
            foreach (self::define() as $lesson) {
                self::$lessons[$lesson->key] = $lesson;
            }
        }

        return self::$lessons;
    }

    public static function get(string $key): ?Lesson
    {
        return self::all()[$key] ?? null;
    }

    /**
     * @return list<Lesson>
     */
    public static function ofFamily(Family $family): array
    {
        return array_values(array_filter(self::all(), static fn (Lesson $lesson): bool => $lesson->family === $family));
    }

    /**
     * @return list<Lesson>
     */
    private static function define(): array
    {
        $win = Result::Win;
        $draw = Result::Draw;
        $centre = [-3, -2, -1, 1, 2];

        return [
            // Mates: the bare king anywhere, pieces placed at random for the variants.
            new Lesson('kqk', Family::BasicMates, Goal::Mate, '8/8/8/4k3/8/8/8/3QK3 w - - 0 1', $win, material: 'KQ-K'),
            new Lesson('krk', Family::BasicMates, Goal::Mate, '8/8/8/4k3/8/8/8/R3K3 w - - 0 1', $win, material: 'KR-K'),
            new Lesson('kbbk', Family::BasicMates, Goal::Mate, '8/8/8/4k3/8/8/8/2B1KB2 w - - 0 1', $win, material: 'KBB-K'),
            new Lesson('kbnk', Family::BasicMates, Goal::Mate, '8/8/8/4k3/8/8/8/1N2KB2 w - - 0 1', $win, material: 'KBN-K'),

            // Pawns.
            new Lesson('square-promote', Family::Pawns, Goal::Promote, '8/8/8/P7/8/8/5k2/7K w - - 0 1', $win),
            new Lesson('square-stop', Family::Pawns, Goal::Hold, '8/8/8/P7/3k4/8/8/7K b - - 0 1', $draw, holdMoves: 8),
            new Lesson('opposition', Family::Pawns, Goal::Promote, '8/8/4k3/8/8/4K3/4P3/8 w - - 0 1', $win, fileShifts: $centre),
            new Lesson('pawn-defence', Family::Pawns, Goal::Hold, '4k3/8/8/8/8/4P3/4K3/8 b - - 0 1', $draw, holdMoves: 12, fileShifts: $centre),
            new Lesson('rook-pawn', Family::Pawns, Goal::Hold, '8/8/1k6/8/8/8/P7/K7 b - - 0 1', $draw, holdMoves: 12),
            new Lesson('key-squares', Family::Pawns, Goal::Promote, '8/8/8/2k5/8/3K4/3P4/8 w - - 0 1', $win, fileShifts: [-2, -1, 1, 2, 3]),
            // Only Ke5 wins: the king loses a tempo by a triangle and hands the move over.
            new Lesson('triangulation', Family::Pawns, Goal::Promote, '8/5k2/5P1p/5K1P/8/8/8/8 w - - 0 1', $win),
            new Lesson('pawn-race', Family::Pawns, Goal::Promote, '8/8/8/7P/p7/8/8/1K4k1 w - - 0 1', $win),

            // Queens.
            new Lesson('queen-vs-pawn', Family::Queens, Goal::Convert, 'K7/8/8/8/8/8/3pk3/7Q w - - 0 1', $win),
            new Lesson('queen-vs-bishop-pawn', Family::Queens, Goal::Hold, 'K7/8/8/8/8/8/2pk1Q2/8 b - - 0 1', $draw, holdMoves: 12),
            new Lesson('queen-vs-rook', Family::Queens, Goal::Convert, '3k4/3r4/3K4/8/8/8/8/7Q w - - 0 1', $win, material: 'KQ-KR'),

            // Rooks.
            // The black king cut off on the f-file: keep it there, the pawn queens.
            new Lesson('king-cut-off', Family::Rooks, Goal::Promote, '1K3k2/1P6/8/8/8/8/r7/2R5 w - - 0 1', $win, fileShifts: [1, 2]),
            new Lesson('lucena', Family::Rooks, Goal::Promote, '1K1k4/1P6/8/8/8/8/r7/2R5 w - - 0 1', $win, fileShifts: [1, 2, 3, 4]),
            new Lesson('philidor', Family::Rooks, Goal::Hold, '4k3/R7/7r/4PK2/8/8/8/8 b - - 0 1', $draw, holdMoves: 15, fileShifts: [-3, -2, -1, 1, 2]),
            new Lesson('rook-vs-pawn', Family::Rooks, Goal::Convert, '8/8/8/8/8/1k6/1p6/4K2R w - - 0 1', $win, fileShifts: [1, 2, 3, 4]),
            new Lesson('vancura', Family::Rooks, Goal::Hold, 'R7/6k1/P4r2/1K6/8/8/8/8 b - - 0 1', $draw, holdMoves: 15),

            // Bishops.
            new Lesson('wrong-rook-pawn', Family::Bishops, Goal::Hold, '8/1k6/8/8/8/P7/8/K1B5 b - - 0 1', $draw, holdMoves: 12),
            new Lesson('bishop-vs-pawn', Family::Bishops, Goal::Hold, '8/8/8/8/1p6/8/2k5/B6K w - - 0 1', $draw, holdMoves: 10),
            new Lesson('opposite-bishops', Family::Bishops, Goal::Hold, '8/3k4/8/3PP3/5K2/8/8/1bB5 b - - 0 1', $draw, holdMoves: 15),
            new Lesson('bishop-pawn', Family::Bishops, Goal::Promote, '8/8/8/4k3/8/8/4P3/4KB2 w - - 0 1', $win, fileShifts: [-3, -2, -1, 1, 2]),

            // Knights.
            new Lesson('knight-vs-pawn', Family::Knights, Goal::Hold, '8/8/8/8/4k3/3p4/8/5N1K w - - 0 1', $draw, holdMoves: 10),
            new Lesson('knight-vs-rook-pawn', Family::Knights, Goal::Hold, '8/8/8/8/1k6/p7/8/4N2K w - - 0 1', $draw, holdMoves: 10),
            new Lesson('knight-pawn', Family::Knights, Goal::Promote, '8/8/8/4k3/8/8/4P3/4K1N1 w - - 0 1', $win, fileShifts: [-3, -2, -1, 1]),
        ];
    }
}
