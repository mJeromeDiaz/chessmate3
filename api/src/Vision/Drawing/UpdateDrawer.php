<?php

declare(strict_types=1);

namespace App\Vision\Drawing;

use App\Blindfold\Recall\PositionGenerator;
use App\Chess\Attacks;
use App\Chess\InvalidPositionException;
use App\Chess\RandomLine;
use App\Enum\Vision\Ask;
use App\Enum\Vision\Kind;
use App\Vision\Board\EnPrise;
use App\Vision\Board\Reach;
use App\Vision\VisionRules;
use Random\Randomizer;

/**
 * Draws the questions on a position updated by moves (docs/VISION.md, § 2 ter): a legal position
 * generated with both kings, White to move ({@see PositionGenerator}), then a random line of legal
 * moves for both sides ({@see RandomLine}), announced once it is hidden; the question is about the
 * position reached. The mini-boss 3.2 asks three questions on it, the last one about a piece en
 * prise.
 */
final readonly class UpdateDrawer
{
    private const SQUARES = 64;

    private Randomizer $randomizer;

    public function __construct(
        private PositionGenerator $generator,
        private RandomLine $lines,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * A question of an `update_*` kind; any other kind draws as the mini-boss 3.2.
     */
    public function draw(Kind $kind): Draw
    {
        [$pieces, $moves, $captures] = match ($kind) {
            Kind::UpdateMissing => [VisionRules::UPDATE_MISSING_PIECES, [3, 4], 1],
            Kind::UpdateCaptures => [VisionRules::UPDATE_CAPTURES_PIECES, [5, 6], 2],
            Kind::UpdateMixed => [VisionRules::UPDATE_MIXED_PIECES, [5, 8], null],
            default => [VisionRules::UPDATE_MIXED_PIECES, [5, 5], null],
        };
        for ($try = 0; $try < VisionRules::UPDATE_TRIES; ++$try) {
            $fen = $this->generator->generate($pieces);
            try {
                $line = $this->lines->draw($fen, $this->randomizer->getInt(...$moves), $captures);
            } catch (InvalidPositionException) {
                continue;
            }
            $draw = null === $line ? null : $this->question($kind, $fen, $line);
            if (null !== $draw) {
                return $draw;
            }
        }
        throw new \RuntimeException('No line could be drawn.');
    }

    /**
     * @param array{moves: list<string>, captures: list<array{square: string, piece: string}>, fen: string} $line
     */
    private function question(Kind $kind, string $fen, array $line): ?Draw
    {
        $final = $line['fen'];
        $draw = fn (Ask $ask, ?string $target, array $expected, array $params = []): Draw => self::make($fen, $line['moves'], $ask, $target, $expected, $params);
        switch ($kind) {
            case Kind::UpdateMissing:
                return $draw(Ask::Missing, null, [$line['captures'][0]['piece'] ?? '']);
            case Kind::UpdateCaptures:
                $squares = array_values(array_unique(array_column($line['captures'], 'square')));
                sort($squares);

                // Two captures on two squares (a recapture on the same one would leave one answer).
                return \count($squares) < \count($line['captures']) ? null : $draw(Ask::Captures, null, $squares);
            case Kind::UpdateMixed:
                $asks = [Ask::What, Ask::Where, Ask::Reach];
                $question = $this->ask($asks[$this->randomizer->getInt(0, 2)], $final);

                return null === $question ? null : $draw($question['ask'], $question['target'], $question['expected'], $question['params']);
            default:
                try {
                    $enPrise = EnPrise::squares($final);
                } catch (InvalidPositionException) {
                    return null;
                }
                $order = 0 === $this->randomizer->getInt(0, 1) ? [Ask::What, Ask::Where] : [Ask::Where, Ask::What];
                $first = $this->ask($order[0], $final);
                $second = $this->ask($order[1], $final);
                if ([] === $enPrise || null === $first || null === $second) {
                    return null;
                }

                return $draw($first['ask'], $first['target'], $first['expected'], [
                    'followUps' => [
                        ['ask' => $second['ask']->value, 'target' => $second['target'], 'expected' => $second['expected']],
                        ['ask' => Ask::EnPrise->value, 'target' => null, 'expected' => $enPrise],
                    ],
                ]);
        }
    }

    /**
     * @param list<string>  $moves
     * @param array<mixed>  $expected
     * @param array<mixed>  $params
     */
    private static function make(string $fen, array $moves, Ask $ask, ?string $target, array $expected, array $params): Draw
    {
        $clean = [];
        foreach ($params as $key => $value) {
            $clean[(string) $key] = $value;
        }

        return new Draw($fen, $moves, $ask, $target, null, array_values(array_filter($expected, 'is_string')), null, VisionRules::UPDATE_SECONDS, $clean);
    }

    /**
     * A question about the position reached: what stands on a square (a piece two times in three),
     * where a piece unique on the board is, whether a piece unique in its side (not a pawn) can go to
     * a square (as often yes as no).
     *
     * @return array{ask: Ask, target: string, expected: list<string>, params: array<string, mixed>}|null
     */
    private function ask(Ask $ask, string $fen): ?array
    {
        $board = Attacks::board($fen);
        $counts = array_count_values($board);
        if (Ask::What === $ask) {
            $occupied = array_keys($board);
            $empty = array_values(array_diff(self::squares(), $occupied));
            $square = $this->randomizer->getInt(0, 2) > 0 || [] === $empty
                ? $occupied[$this->randomizer->getInt(0, \count($occupied) - 1)]
                : $empty[$this->randomizer->getInt(0, \count($empty) - 1)];

            return ['ask' => $ask, 'target' => $square, 'expected' => [$board[$square] ?? ''], 'params' => []];
        }
        $unique = array_keys(array_filter($board, static fn (string $piece): bool => 1 === $counts[$piece]));
        if (Ask::Where === $ask) {
            $square = $unique[$this->randomizer->getInt(0, \count($unique) - 1)];

            return ['ask' => $ask, 'target' => $board[$square], 'expected' => [$square], 'params' => []];
        }
        $movers = array_values(array_filter($unique, static fn (string $square): bool => 'p' !== strtolower($board[$square])));
        if ([] === $movers) {
            return null;
        }
        $from = $movers[$this->randomizer->getInt(0, \count($movers) - 1)];
        $reachable = [];
        $not = [];
        foreach (self::squares() as $square) {
            if ($square === $from) {
                continue;
            }
            if (Reach::canReach($fen, $from, $square)) {
                $reachable[] = $square;
            } elseif (!isset($board[$square])) {
                $not[] = $square;
            }
        }
        $yes = [] !== $reachable && ([] === $not || 0 === $this->randomizer->getInt(0, 1));
        $choices = $yes ? $reachable : $not;
        if ([] === $choices) {
            return null;
        }

        return [
            'ask' => Ask::Reach,
            'target' => $choices[$this->randomizer->getInt(0, \count($choices) - 1)],
            'expected' => [$yes ? 'yes' : 'no'],
            'params' => ['piece' => $board[$from], 'from' => $from],
        ];
    }

    /**
     * @return list<string> a1 to h8
     */
    private static function squares(): array
    {
        $squares = [];
        for ($i = 0; $i < self::SQUARES; ++$i) {
            $squares[] = \chr(\ord('a') + $i % 8).(intdiv($i, 8) + 1);
        }

        return $squares;
    }
}
