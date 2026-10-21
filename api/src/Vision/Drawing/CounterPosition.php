<?php

declare(strict_types=1);

namespace App\Vision\Drawing;

use App\Chess\Attacks;
use App\Chess\InvalidPositionException;
use App\Chess\Rules;
use App\Entity\Catalog\Puzzle;
use App\Enum\Vision\Ask;
use App\Vision\VisionRules;
use Random\Randomizer;

/**
 * A puzzle made into a position of the Compteur (docs/VISION.md, § 2 sexies): shown as it starts,
 * its opponent's first move and its solution announced (8 to 10 half-moves), then 3 questions on the
 * position they reach, in a random order: what stands on a square, where a piece stands, how many
 * pieces a side has left.
 */
final readonly class CounterPosition
{
    private Randomizer $randomizer;

    public function __construct(
        private UpdateDrawer $updates,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    /**
     * A puzzle made into the Compteur's position, null when it does not fit (too short, a move that
     * does not replay).
     */
    public function of(Puzzle $puzzle): ?Draw
    {
        [$fewest, $most] = VisionRules::COUNTER_PLIES;
        $moves = explode(' ', trim($puzzle->getMoves()));
        if (\count($moves) < $fewest) {
            return null;
        }
        $announced = \array_slice($moves, 0, $most);
        try {
            $rules = Rules::fromFen($puzzle->getFen());
            $shown = $rules->fen();
            foreach ($announced as $uci) {
                if (null === $rules->playUci($uci)) {
                    return null;
                }
            }
            $questions = $this->questions($rules->fen());
        } catch (InvalidPositionException) {
            return null;
        }
        if (\count($questions) < VisionRules::COUNTER_QUESTIONS) {
            return null;
        }
        $first = array_shift($questions);

        return new Draw($shown, $announced, $first['ask'], $first['target'], $first['side'], $first['expected'], (int) $puzzle->getId(), VisionRules::COUNTER_SECONDS, [
            'choices' => $first['choices'],
            'followUps' => array_map(static fn (array $q): array => ['ask' => $q['ask']->value, 'target' => $q['target'], 'side' => $q['side'], 'choices' => $q['choices'], 'expected' => $q['expected']], $questions),
        ]);
    }

    /**
     * The 3 questions on the position reached, in a random order.
     *
     * @return list<array{ask: Ask, target: string|null, side: string|null, choices: list<string>|null, expected: list<string>}>
     *
     * @throws InvalidPositionException
     */
    private function questions(string $fen): array
    {
        $questions = [];
        foreach ($this->randomizer->shuffleArray([Ask::Where, Ask::What, Ask::Count]) as $ask) {
            if (Ask::Count === $ask) {
                $questions[] = $this->count($fen);
                continue;
            }
            $question = $this->updates->askAbout($ask, $fen);
            if (null !== $question) {
                $questions[] = ['ask' => $question['ask'], 'target' => $question['target'], 'side' => null, 'choices' => null, 'expected' => $question['expected']];
            }
        }

        return $questions;
    }

    /**
     * « Combien de pièces noires restent ? » (the king counted), among COUNTER_CHOICES numbers in a
     * row that hold the answer.
     *
     * @return array{ask: Ask, target: null, side: string, choices: list<string>, expected: list<string>}
     */
    private function count(string $fen): array
    {
        $white = 0 === $this->randomizer->getInt(0, 1);
        $left = \count(array_filter(Attacks::board($fen), static fn (string $piece): bool => $white === ctype_upper($piece)));
        $from = max(1, min(17 - VisionRules::COUNTER_CHOICES, $left - $this->randomizer->getInt(0, VisionRules::COUNTER_CHOICES - 1)));

        return [
            'ask' => Ask::Count,
            'target' => null,
            'side' => $white ? 'white' : 'black',
            'choices' => array_map('strval', range($from, $from + VisionRules::COUNTER_CHOICES - 1)),
            'expected' => [(string) $left],
        ];
    }
}
