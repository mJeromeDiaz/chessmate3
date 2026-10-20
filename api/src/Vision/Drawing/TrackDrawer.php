<?php

declare(strict_types=1);

namespace App\Vision\Drawing;

use App\Enum\Vision\Ask;
use App\Enum\Vision\Kind;
use App\Vision\VisionRules;
use Random\Randomizer;

/**
 * Draws the questions on a piece's path (docs/VISION.md, § 2 bis): a white piece among 3 to 6 black
 * ones on a board without kings, then a path where only it moves, each move legal for it (a line
 * stopped by the first piece met, a capture of a black piece, never back to the square it just
 * left), captures where its kind asks for them. The moves are written as « Rd1, Rd5, Rxd7 » (SAN,
 * English letters). Also the knight's question, without position: its fewest jumps between two
 * squares.
 */
final readonly class TrackDrawer
{
    public const EMPTY_FEN = '8/8/8/8/8/8/8/8 w - - 0 1';

    private const KNIGHT = [[1, 2], [2, 1], [2, -1], [1, -2], [-1, -2], [-2, -1], [-2, 1], [-1, 2]];
    private const LINES = [[1, 0], [0, 1], [-1, 0], [0, -1]];
    private const DIAGONALS = [[1, 1], [-1, 1], [-1, -1], [1, -1]];
    /** The black pieces that may stand around (a pawn more often). */
    private const BLACK = ['p', 'p', 'p', 'n', 'b', 'r'];
    private const TRIES = 300;

    private Randomizer $randomizer;

    public function __construct(?Randomizer $randomizer = null)
    {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * A question of a `track_*` kind or the knight's; any other kind draws as the mini-boss 3.1.
     */
    public function draw(Kind $kind): Draw
    {
        if (Kind::KnightDistance === $kind) {
            return $this->knight();
        }
        [$pieces, $moves, $captures, $turns] = match ($kind) {
            Kind::TrackCapture => [['R', 'B'], [2, 3], 'last', false],
            Kind::TrackSquare => [['R', 'B', 'Q', 'N'], [2, 4], [0, 0], false],
            Kind::TrackTurns => [['R', 'B', 'Q'], [3, 5], [1, 2], true],
            Kind::TrackLong => [['R', 'B', 'Q', 'N'], [5, 8], [0, 2], false],
            default => [['R', 'B', 'Q', 'N'], [4, 6], [1, 2], false],
        };
        for ($try = 0; $try < self::TRIES; ++$try) {
            $path = $this->path($pieces[$this->randomizer->getInt(0, \count($pieces) - 1)], $this->randomizer->getInt(...$moves), $captures, $turns);
            if (null !== $path) {
                return $this->question($kind, $path);
            }
        }
        throw new \RuntimeException('No path could be drawn.');
    }

    /**
     * A knight's fewest jumps from one square to another, 1 to 5.
     */
    public static function knightDistance(string $from, string $to): int
    {
        $start = self::coordinates($from);
        $goal = self::coordinates($to);
        $seen = [$from => 0];
        /** @var list<array{int, int}> $queue */
        $queue = [$start];
        while ([] !== $queue) {
            [$file, $rank] = array_shift($queue);
            $distance = $seen[self::name($file, $rank) ?? ''] ?? 0;
            if ([$file, $rank] === $goal) {
                return $distance;
            }
            foreach (self::KNIGHT as [$df, $dr]) {
                $next = self::name($file + $df, $rank + $dr);
                if (null !== $next && !isset($seen[$next])) {
                    $seen[$next] = $distance + 1;
                    $queue[] = [$file + $df, $rank + $dr];
                }
            }
        }

        return 0;
    }

    private function knight(): Draw
    {
        do {
            $from = (string) self::name($this->randomizer->getInt(0, 7), $this->randomizer->getInt(1, 8));
            $to = (string) self::name($this->randomizer->getInt(0, 7), $this->randomizer->getInt(1, 8));
        } while (self::knightDistance($from, $to) < 1);
        $distance = self::knightDistance($from, $to);

        return new Draw(self::EMPTY_FEN, [], Ask::KnightJumps, $from, null, [(string) $distance], null, 0, ['to' => $to]);
    }

    /**
     * A path of $count moves for a white $piece; $captures: 'last' (only the last move takes), or
     * how many may take ([min, max], placed at random); $turns: each move changes direction.
     *
     * @param array{int, int}|'last' $captures
     *
     * @return array{piece: string, start: array<string, string>, final: array<string, string>, moves: list<array{uci: string, san: string}>, stops: list<string>, captures: array<string, string>}|null
     */
    private function path(string $piece, int $count, array|string $captures, bool $turns): ?array
    {
        $taking = 'last' === $captures
            ? [$count - 1]
            : \array_slice($this->randomizer->shuffleArray(range(0, $count - 1)), 0, $this->randomizer->getInt(...$captures));
        $board = $this->board($piece, max(3, \count($taking) + 2));
        $start = $board;
        $current = (string) array_search($piece, $board, true);
        $previous = null;
        $direction = null;
        $moves = [];
        $stops = [];
        $taken = [];
        for ($i = 0; $i < $count; ++$i) {
            $options = array_values(array_filter(
                self::destinations($board, $current, $piece),
                static fn (array $d): bool => $d['to'] !== $previous
                    && (null !== $d['captured']) === \in_array($i, $taking, true)
                    && (!$turns || $d['direction'] !== $direction),
            ));
            if ([] === $options) {
                return null;
            }
            $move = $options[$this->randomizer->getInt(0, \count($options) - 1)];
            unset($board[$current]);
            $board[$move['to']] = $piece;
            if (null !== $move['captured']) {
                $taken[$move['to']] = $move['captured'];
            }
            $moves[] = ['uci' => $current.$move['to'], 'san' => $piece.(null !== $move['captured'] ? 'x' : '').$move['to']];
            $stops[] = $move['to'];
            $previous = $current;
            $current = $move['to'];
            $direction = $move['direction'];
        }

        return ['piece' => $piece, 'start' => $start, 'final' => $board, 'moves' => $moves, 'stops' => $stops, 'captures' => $taken];
    }

    /**
     * @param array{piece: string, start: array<string, string>, final: array<string, string>, moves: list<array{uci: string, san: string}>, stops: list<string>, captures: array<string, string>} $path
     */
    private function question(Kind $kind, array $path): Draw
    {
        $stops = $path['stops'];
        $final = $stops[\count($stops) - 1];
        $captured = array_keys($path['captures']);
        sort($captured);
        $sortedStops = array_values(array_unique($stops));
        sort($sortedStops);
        $params = [
            'piece' => $path['piece'],
            'moves' => $path['moves'],
            'finalFen' => self::fen($path['final']),
            'captures' => $captured,
            'stops' => $sortedStops,
        ];
        if (Kind::TrackBoss === $kind) {
            // Then, on the same path: where were the pieces it took, where did it stop.
            $params['followUps'] = [
                ['ask' => Ask::Captures->value, 'target' => null, 'expected' => $captured],
                ['ask' => Ask::Stops->value, 'target' => null, 'expected' => $sortedStops],
            ];
        }
        $ask = match ($kind) {
            Kind::TrackCapture => Ask::Captured,
            Kind::TrackLong => [Ask::Arrival, Ask::Passed, ...([] === $captured ? [] : [Ask::Captured])][$this->randomizer->getInt(0, [] === $captured ? 1 : 2)],
            default => Ask::Arrival,
        };
        $start = array_search($path['piece'], $path['start'], true);
        [$target, $expected] = match ($ask) {
            Ask::Captured => [$captured[$this->randomizer->getInt(0, \count($captured) - 1)], null],
            Ask::Passed => $this->passed($stops, \is_string($start) ? $start : ''),
            default => [null, [$final]],
        };
        if (Ask::Captured === $ask) {
            $expected = [$path['captures'][(string) $target]];
        }

        return new Draw(self::fen($path['start']), array_column($path['moves'], 'uci'), $ask, $target, null, $expected ?? [], null, VisionRules::TRACK_SECONDS, $params);
    }

    /**
     * « Est-elle passée par… ? »: one of its stops, or a square it never stopped on (not its start).
     *
     * @param list<string> $stops
     *
     * @return array{string, list<string>}
     */
    private function passed(array $stops, string $start): array
    {
        if (0 === $this->randomizer->getInt(0, 1)) {
            return [$stops[$this->randomizer->getInt(0, \count($stops) - 1)], ['yes']];
        }
        do {
            $square = (string) self::name($this->randomizer->getInt(0, 7), $this->randomizer->getInt(1, 8));
        } while (\in_array($square, $stops, true) || $square === $start);

        return [$square, ['no']];
    }

    /**
     * The white $piece and $others black pieces on random squares (no pawn on the first or last rank).
     *
     * @return array<string, string>
     */
    private function board(string $piece, int $others): array
    {
        $board = [];
        $count = $this->randomizer->getInt($others, max($others, 6));
        $letters = [$piece];
        for ($i = 0; $i < $count; ++$i) {
            $letters[] = self::BLACK[$this->randomizer->getInt(0, \count(self::BLACK) - 1)];
        }
        foreach ($letters as $letter) {
            do {
                $rank = 'p' === $letter ? $this->randomizer->getInt(2, 7) : $this->randomizer->getInt(1, 8);
                $square = (string) self::name($this->randomizer->getInt(0, 7), $rank);
            } while (isset($board[$square]));
            $board[$square] = $letter;
        }

        return $board;
    }

    /**
     * Where the white $piece on $from may go: empty squares and black pieces it can take.
     *
     * @param array<string, string> $board
     *
     * @return list<array{to: string, captured: string|null, direction: string}>
     */
    private static function destinations(array $board, string $from, string $piece): array
    {
        [$file, $rank] = self::coordinates($from);
        $found = [];
        if ('N' === $piece) {
            foreach (self::KNIGHT as $i => [$df, $dr]) {
                $to = self::name($file + $df, $rank + $dr);
                if (null !== $to && !ctype_upper($board[$to] ?? 'x')) {
                    $found[] = ['to' => $to, 'captured' => $board[$to] ?? null, 'direction' => 'n'.$i];
                }
            }

            return $found;
        }
        $directions = match ($piece) {
            'R' => self::LINES,
            'B' => self::DIAGONALS,
            default => [...self::LINES, ...self::DIAGONALS],
        };
        foreach ($directions as [$df, $dr]) {
            for ($step = 1; $step < 8; ++$step) {
                $to = self::name($file + $df * $step, $rank + $dr * $step);
                if (null === $to) {
                    break;
                }
                $there = $board[$to] ?? null;
                if (null !== $there && ctype_upper($there)) {
                    break;
                }
                $found[] = ['to' => $to, 'captured' => $there, 'direction' => $df.':'.$dr];
                if (null !== $there) {
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * A board as a FEN (White to move, no castling).
     *
     * @param array<string, string> $board
     */
    private static function fen(array $board): string
    {
        $rows = [];
        for ($rank = 8; $rank >= 1; --$rank) {
            $row = '';
            $empty = 0;
            for ($file = 0; $file < 8; ++$file) {
                $piece = $board[self::name($file, $rank) ?? ''] ?? null;
                if (null === $piece) {
                    ++$empty;
                    continue;
                }
                $row .= ($empty > 0 ? (string) $empty : '').$piece;
                $empty = 0;
            }
            $rows[] = $row.($empty > 0 ? (string) $empty : '');
        }

        return implode('/', $rows).' w - - 0 1';
    }

    /**
     * @return array{int, int} file 0–7, rank 1–8
     */
    private static function coordinates(string $square): array
    {
        return [\ord($square[0]) - \ord('a'), (int) $square[1]];
    }

    private static function name(int $file, int $rank): ?string
    {
        return $file >= 0 && $file < 8 && $rank >= 1 && $rank <= 8 ? \chr(\ord('a') + $file).$rank : null;
    }
}
