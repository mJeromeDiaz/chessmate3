<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vision;

use App\Chess\Attacks;
use App\Enum\Vision\Ask;
use App\Enum\Vision\Kind;
use App\Vision\Drawing\Draw;
use App\Vision\Drawing\TrackDrawer;
use PHPUnit\Framework\TestCase;

/**
 * The questions on a piece's path (docs/VISION.md, § 2 bis): only the white piece moves, each move
 * legal for it, the captures its kind asks for, answers consistent with the path; a knight's
 * fewest jumps.
 */
final class TrackDrawerTest extends TestCase
{
    public function testAKnightsFewestJumps(): void
    {
        self::assertSame([1, 4, 6, 4, 3, 0], [
            TrackDrawer::knightDistance('a1', 'b3'),
            TrackDrawer::knightDistance('a1', 'b2'),
            TrackDrawer::knightDistance('a1', 'h8'),
            TrackDrawer::knightDistance('b1', 'f7'),
            TrackDrawer::knightDistance('e4', 'e5'),
            TrackDrawer::knightDistance('e4', 'e4'),
        ]);
        $draw = (new TrackDrawer())->draw(Kind::KnightDistance);
        self::assertSame([Ask::KnightJumps, TrackDrawer::EMPTY_FEN, 0], [$draw->ask, $draw->fen, $draw->visibleSeconds]);
        self::assertIsString($draw->params['to'] ?? null);
        self::assertSame([(string) TrackDrawer::knightDistance((string) $draw->target, $draw->params['to'])], $draw->expected);
    }

    /**
     * @return iterable<string, array{Kind, int, int, int, int}>
     */
    public static function kinds(): iterable
    {
        // Kind, moves min, max, captures min, max.
        yield 'track_capture' => [Kind::TrackCapture, 2, 3, 1, 1];
        yield 'track_square' => [Kind::TrackSquare, 2, 4, 0, 0];
        yield 'track_turns' => [Kind::TrackTurns, 3, 5, 1, 2];
        yield 'track_long' => [Kind::TrackLong, 5, 8, 0, 2];
        yield 'track_boss' => [Kind::TrackBoss, 4, 6, 1, 2];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('kinds')]
    public function testAPathAndItsQuestion(Kind $kind, int $fewest, int $most, int $fewestCaptures, int $mostCaptures): void
    {
        $drawer = new TrackDrawer();
        for ($i = 0; $i < 100; ++$i) {
            $draw = $drawer->draw($kind);
            $moves = self::moves($draw);
            self::assertGreaterThanOrEqual($fewest, \count($moves));
            self::assertLessThanOrEqual($most, \count($moves));
            $board = Attacks::board($draw->fen);
            $piece = $draw->params['piece'] ?? null;
            self::assertIsString($piece);
            self::assertContains($piece, ['R', 'B', 'Q', 'N']);
            $finalFen = $draw->params['finalFen'] ?? null;
            self::assertIsString($finalFen);
            self::assertCount(1, array_filter($board, 'ctype_upper'), 'One white piece, the one that moves.');

            $captures = [];
            $stops = [];
            $direction = null;
            foreach ($moves as $j => ['uci' => $uci, 'san' => $san]) {
                [$from, $to] = [substr($uci, 0, 2), substr($uci, 2, 2)];
                self::assertSame($piece, $board[$from] ?? null);
                self::assertTrue(self::reaches($board, $from, $to, $piece), "$san from $from");
                $taken = $board[$to] ?? null;
                self::assertSame($piece.(null === $taken ? '' : 'x').$to, $san);
                if (null !== $taken) {
                    self::assertTrue(ctype_lower($taken), 'Only black pieces are taken.');
                    $captures[] = $to;
                }
                if (Kind::TrackCapture === $kind) {
                    self::assertSame($j === \count($moves) - 1, null !== $taken, 'Only the last move takes.');
                }
                if (Kind::TrackTurns === $kind) {
                    $turn = self::direction($from, $to);
                    self::assertNotSame($direction, $turn, 'A turn at each move.');
                    $direction = $turn;
                }
                unset($board[$from]);
                $board[$to] = $piece;
                $stops[] = $to;
            }
            self::assertGreaterThanOrEqual($fewestCaptures, \count($captures));
            self::assertLessThanOrEqual($mostCaptures, \count($captures));
            $reached = Attacks::board($finalFen);
            ksort($board);
            ksort($reached);
            self::assertSame($board, $reached, 'The position reached.');

            $final = $stops[\count($stops) - 1];
            $expected = match ($draw->ask) {
                Ask::Arrival => [$final],
                Ask::Captured => [Attacks::board($draw->fen)[(string) $draw->target] ?? null],
                Ask::Passed => [\in_array($draw->target, $stops, true) ? 'yes' : 'no'],
                default => null,
            };
            self::assertSame($expected, $draw->expected, $draw->ask->value);
            sort($captures);
            $sortedStops = array_values(array_unique($stops));
            sort($sortedStops);
            self::assertSame([$captures, $sortedStops], [$draw->params['captures'] ?? null, $draw->params['stops'] ?? null]);
        }
    }

    /**
     * @return list<array{uci: string, san: string}>
     */
    private static function moves(Draw $draw): array
    {
        $moves = $draw->params['moves'] ?? null;
        self::assertIsArray($moves);

        /** @var list<array{uci: string, san: string}> */
        return $moves;
    }

    /**
     * Whether $piece on $from reaches $to: a knight's jump, or a line with nothing in between.
     *
     * @param array<string, string> $board
     */
    private static function reaches(array $board, string $from, string $to, string $piece): bool
    {
        $df = \ord($to[0]) - \ord($from[0]);
        $dr = (int) $to[1] - (int) $from[1];
        if ('N' === $piece) {
            return [1, 2] === [min(abs($df), abs($dr)), max(abs($df), abs($dr))];
        }
        $straight = 0 === $df || 0 === $dr;
        $diagonal = abs($df) === abs($dr);
        if (('R' === $piece && !$straight) || ('B' === $piece && !$diagonal) || (!$straight && !$diagonal) || [0, 0] === [$df, $dr]) {
            return false;
        }
        $steps = max(abs($df), abs($dr));
        for ($k = 1; $k < $steps; ++$k) {
            $square = \chr(\ord($from[0]) + intdiv($df, $steps) * $k).((int) $from[1] + intdiv($dr, $steps) * $k);
            if (isset($board[$square])) {
                return false;
            }
        }

        return true;
    }

    private static function direction(string $from, string $to): string
    {
        $df = \ord($to[0]) - \ord($from[0]);
        $dr = (int) $to[1] - (int) $from[1];

        return ($df <=> 0).':'.($dr <=> 0);
    }
}
