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
use App\Vision\Board\Relations;
use App\Vision\VisionRules;
use Random\Randomizer;

/**
 * Draws the questions on the relations between pieces (docs/VISION.md, § 2 quater): as for a
 * position updated by moves ({@see UpdateDrawer}), a legal position then 3 to 5 moves of both
 * sides; the question asks for every piece in a relation in the position reached (attacked,
 * defended or not, aligned with one of them, en prise), never an empty answer. The mini-boss 3.3
 * asks, on each position, the pieces of a side attacked, then defended, then the winning captures.
 */
final readonly class RelationDrawer
{
    private Randomizer $randomizer;

    public function __construct(
        private PositionGenerator $generator,
        private RandomLine $lines,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * A question of a `rel_*` kind; any other kind draws as the mini-boss 3.3.
     */
    public function draw(Kind $kind): Draw
    {
        $dense = Kind::RelationMixed === $kind;
        for ($try = 0; $try < VisionRules::UPDATE_TRIES; ++$try) {
            $fen = $this->generator->generate($dense ? VisionRules::RELATION_DENSE_PIECES : VisionRules::RELATION_PIECES);
            try {
                $line = $this->lines->draw($fen, $dense ? 5 : $this->randomizer->getInt(3, 5));
                $draw = null === $line ? null : $this->question($kind, $fen, $line['moves'], $line['fen']);
            } catch (InvalidPositionException) {
                continue;
            }
            if (null !== $draw) {
                return $draw;
            }
        }
        throw new \RuntimeException('No position could be drawn.');
    }

    /**
     * @param list<string> $moves
     *
     * @throws InvalidPositionException
     */
    private function question(Kind $kind, string $fen, array $moves, string $final): ?Draw
    {
        $kinds = [Kind::RelationAttacked, Kind::RelationDefended, Kind::RelationAligned, Kind::RelationEnPrise];
        if (Kind::RelationMixed === $kind) {
            $kind = $kinds[$this->randomizer->getInt(0, 3)];
        }
        $side = 0 === $this->randomizer->getInt(0, 1) ? 'w' : 'b';
        // En prise: the pieces of the side not to move.
        $notToMove = 'w' === (explode(' ', $final)[1] ?? 'w') ? 'b' : 'w';
        $question = match ($kind) {
            Kind::RelationAttacked => [Ask::Attacked, null, $side, Relations::attacked($final, $side)],
            Kind::RelationDefended => 0 === $this->randomizer->getInt(0, 1)
                ? [Ask::Defended, null, $side, Relations::defended($final, $side)]
                : [Ask::Undefended, null, $side, Relations::undefended($final, $side)],
            Kind::RelationAligned => $this->alignedWith($final),
            Kind::RelationEnPrise => [Ask::EnPriseAll, null, $notToMove, EnPrise::squares($final)],
            default => null,
        };
        if (null !== $question) {
            [$ask, $target, $color, $expected] = $question;

            return [] === $expected ? null : new Draw($fen, $moves, $ask, $target, self::sideName($color), $expected, null, VisionRules::UPDATE_SECONDS);
        }
        // The mini-boss: a side's pieces attacked, then defended, then what its opponent wins.
        $attacked = Relations::attacked($final, $notToMove);
        $defended = Relations::defended($final, $notToMove);
        $winning = EnPrise::squares($final);
        // A piece attacked but no winning capture (defended, or by a piece worth no less): the two
        // questions tell them apart.
        if ([] === $defended || [] === $winning || [] === array_diff($attacked, $winning)) {
            return null;
        }

        return new Draw($fen, $moves, Ask::Attacked, null, self::sideName($notToMove), $attacked, null, VisionRules::UPDATE_SECONDS, [
            'followUps' => [
                ['ask' => Ask::Defended->value, 'target' => null, 'side' => self::sideName($notToMove), 'expected' => $defended],
                ['ask' => Ask::Winning->value, 'target' => null, 'side' => self::sideName($notToMove), 'expected' => $winning],
            ],
        ]);
    }

    /**
     * The pieces aligned with a piece unique on the board (its letter as the target, « la tour
     * blanche »).
     *
     * @return array{Ask, string, null, list<string>}|null
     */
    public function alignedWith(string $fen): ?array
    {
        $board = Attacks::board($fen);
        $counts = array_count_values($board);
        $unique = array_values(array_filter(array_map('strval', array_keys($board)), static fn (string $square): bool => 1 === $counts[$board[$square]]));
        if ([] === $unique) {
            return null;
        }
        $square = $unique[$this->randomizer->getInt(0, \count($unique) - 1)];

        return [Ask::Aligned, $board[$square], null, Relations::aligned($fen, $square)];
    }

    /** @param 'w'|'b'|null $color */
    private static function sideName(?string $color): ?string
    {
        return null === $color ? null : ('w' === $color ? 'white' : 'black');
    }
}
