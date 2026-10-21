<?php

declare(strict_types=1);

namespace App\Vision\Drawing;

use App\Blindfold\Recall\PositionGenerator;
use App\Chess\InvalidPositionException;
use App\Chess\RandomLine;
use App\Enum\Vision\Ask;
use App\Vision\Board\EnPrise;
use App\Vision\Board\Relations;
use App\Vision\VisionRules;
use Random\Randomizer;

/**
 * Draws a position of the Gargouille, boss of chapter 3 (docs/VISION.md, § 2 quinquies): a legal
 * position of 10 pieces, 5 moves of both sides announced, then 5 questions in a row on the position
 * reached, each of another kind, among those of the chapter (where, what, can it go, attacked,
 * aligned, en prise); never an empty answer.
 */
final readonly class GargoyleDrawer
{
    private Randomizer $randomizer;

    public function __construct(
        private PositionGenerator $generator,
        private RandomLine $lines,
        private UpdateDrawer $updates,
        private RelationDrawer $relations,
        ?Randomizer $randomizer = null,
    ) {
        $this->randomizer = $randomizer ?? new Randomizer();
    }

    public function draw(): Draw
    {
        for ($try = 0; $try < VisionRules::UPDATE_TRIES; ++$try) {
            $fen = $this->generator->generate(VisionRules::RELATION_PIECES);
            try {
                $line = $this->lines->draw($fen, VisionRules::GARGOYLE_MOVES);
                $questions = null === $line ? [] : $this->questions($line['fen']);
            } catch (InvalidPositionException) {
                continue;
            }
            if (null === $line || \count($questions) < VisionRules::GARGOYLE_QUESTIONS) {
                continue;
            }
            $first = array_shift($questions);

            return new Draw($fen, $line['moves'], $first['ask'], $first['target'], $first['side'], $first['expected'], null, VisionRules::UPDATE_SECONDS, [
                'piece' => $first['piece'],
                'followUps' => array_map(static fn (array $q): array => ['ask' => $q['ask']->value, 'target' => $q['target'], 'side' => $q['side'], 'piece' => $q['piece'], 'expected' => $q['expected']], $questions),
            ]);
        }
        throw new \RuntimeException('No position could be drawn.');
    }

    /**
     * Up to GARGOYLE_QUESTIONS questions on $fen, each of another kind, in a random order.
     *
     * @return list<array{ask: Ask, target: string|null, side: string|null, piece: string|null, expected: list<string>}>
     *
     * @throws InvalidPositionException
     */
    private function questions(string $fen): array
    {
        $side = 0 === $this->randomizer->getInt(0, 1) ? 'w' : 'b';
        $notToMove = 'w' === (explode(' ', $fen)[1] ?? 'w') ? 'b' : 'w';
        $name = static fn (string $color): string => 'w' === $color ? 'white' : 'black';
        $questions = [];
        foreach ($this->randomizer->shuffleArray([Ask::Where, Ask::What, Ask::Reach, Ask::Attacked, Ask::Aligned, Ask::EnPriseAll]) as $ask) {
            $question = match ($ask) {
                Ask::Where, Ask::What, Ask::Reach => self::fromUpdate($this->updates->askAbout($ask, $fen)),
                Ask::Aligned => self::fromAligned($this->relations->alignedWith($fen)),
                Ask::Attacked => ['ask' => $ask, 'target' => null, 'side' => $name($side), 'piece' => null, 'expected' => Relations::attacked($fen, $side)],
                default => ['ask' => Ask::EnPriseAll, 'target' => null, 'side' => $name($notToMove), 'piece' => null, 'expected' => EnPrise::squares($fen)],
            };
            if (null !== $question && [] !== $question['expected']) {
                $questions[] = $question;
            }
        }

        return \array_slice($questions, 0, VisionRules::GARGOYLE_QUESTIONS);
    }

    /**
     * @param array{ask: Ask, target: string, expected: list<string>, params: array<string, mixed>}|null $question
     *
     * @return array{ask: Ask, target: string|null, side: string|null, piece: string|null, expected: list<string>}|null
     */
    private static function fromUpdate(?array $question): ?array
    {
        if (null === $question) {
            return null;
        }
        // « Le fou blanc peut-il… » names its piece.
        $piece = $question['params']['piece'] ?? null;

        return ['ask' => $question['ask'], 'target' => $question['target'], 'side' => null, 'piece' => \is_string($piece) ? $piece : null, 'expected' => $question['expected']];
    }

    /**
     * @param array{Ask, string, null, list<string>}|null $question
     *
     * @return array{ask: Ask, target: string|null, side: string|null, piece: string|null, expected: list<string>}|null
     */
    private static function fromAligned(?array $question): ?array
    {
        return null === $question ? null : ['ask' => $question[0], 'target' => $question[1], 'side' => null, 'piece' => null, 'expected' => $question[3]];
    }
}
