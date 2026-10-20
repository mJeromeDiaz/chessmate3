<?php

declare(strict_types=1);

namespace App\Vision\Drawing;

use App\Blindfold\Recall\PositionGenerator;
use App\Blindfold\Recall\RecallRules;
use App\Chess\Attacks;
use App\Enum\Vision\Ask;
use App\Vision\VisionRules;
use Random\Randomizer;

/**
 * Draws a `locate` question (docs/VISION.md, § 2): a position generated as the position recall's
 * level {@see VisionRules::LOCATE_LEVEL}, then, as often, « where is this piece? » (any square
 * holding it) or « what stands on this square? » (a piece two times in three, else an empty square).
 */
final readonly class LocateDrawer
{
    private Randomizer $randomizer;

    public function __construct(
        private PositionGenerator $generator,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    public function draw(): Draw
    {
        $fen = $this->generator->generate(RecallRules::level(VisionRules::LOCATE_LEVEL)['pieces']);
        $board = Attacks::board($fen);
        $occupied = array_keys($board);
        if (0 === $this->randomizer->getInt(0, 1)) {
            $piece = $board[$occupied[$this->randomizer->getInt(0, \count($occupied) - 1)]];
            $squares = array_keys(array_filter($board, static fn (string $p): bool => $p === $piece));
            sort($squares);

            return new Draw($fen, [], Ask::Where, $piece, null, $squares, null, VisionRules::LOCATE_SECONDS);
        }
        if ($this->randomizer->getInt(0, 2) > 0) {
            $square = $occupied[$this->randomizer->getInt(0, \count($occupied) - 1)];
        } else {
            $empty = [];
            foreach (range(0, 63) as $index) {
                $name = \chr(\ord('a') + $index % 8).(intdiv($index, 8) + 1);
                if (!isset($board[$name])) {
                    $empty[] = $name;
                }
            }
            $square = $empty[$this->randomizer->getInt(0, \count($empty) - 1)];
        }

        return new Draw($fen, [], Ask::What, $square, null, [$board[$square] ?? ''], null, VisionRules::LOCATE_SECONDS);
    }
}
