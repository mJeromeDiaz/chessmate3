<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vision;

use App\Chess\Attacks;
use App\Chess\Rules;
use App\DataFixtures\Puzzle\SamplePuzzles;
use App\Enum\Vision\Ask;
use App\Blindfold\Recall\PositionGenerator;
use App\Chess\RandomLine;
use App\Vision\Drawing\CounterPosition;
use App\Vision\Drawing\UpdateDrawer;
use App\Vision\VisionRules;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * The Compteur's positions (docs/VISION.md, § 2 sexies): a long puzzle as it starts, 8 to 10
 * half-moves announced, 3 questions on the position they reach (what, where, how many).
 */
final class CounterPositionTest extends TestCase
{
    public function testALongPuzzleMakesThreeQuestionsOnWhereItEnds(): void
    {
        $long = array_values(array_filter(SamplePuzzles::create(), static fn ($p): bool => \count(explode(' ', $p->getMoves())) >= 8));
        self::assertNotSame([], $long, 'A sample puzzle of 8 half-moves or more.');
        $puzzle = $long[0];

        for ($seed = 0; $seed < 20; ++$seed) {
            $randomizer = new Randomizer(new Mt19937($seed));
            $positions = new CounterPosition(new UpdateDrawer(new PositionGenerator(), new RandomLine(), $randomizer), $randomizer);
            $draw = $positions->of($puzzle);
            self::assertNotNull($draw);
            self::assertSame($puzzle->getFen(), $draw->fen, 'Shown as it starts.');
            self::assertGreaterThanOrEqual(VisionRules::COUNTER_PLIES[0], \count($draw->announced));
            self::assertLessThanOrEqual(VisionRules::COUNTER_PLIES[1], \count($draw->announced));

            $rules = Rules::fromFen($draw->fen);
            foreach ($draw->announced as $uci) {
                self::assertNotNull($rules->playUci($uci));
            }
            $board = Attacks::board($rules->fen());
            $followUps = $draw->params['followUps'] ?? null;
            self::assertIsArray($followUps);
            self::assertCount(2, $followUps);
            $asks = [$draw->ask->value, ...array_map(static fn (mixed $q): mixed => \is_array($q) ? $q['ask'] ?? null : null, $followUps)];
            self::assertEqualsCanonicalizing(['where', 'what', 'count'], $asks);

            $count = Ask::Count === $draw->ask ? ['side' => $draw->side, 'choices' => $draw->params['choices'] ?? null, 'expected' => $draw->expected] : null;
            foreach ($followUps as $followUp) {
                if (\is_array($followUp) && 'count' === ($followUp['ask'] ?? null)) {
                    $count = $followUp;
                }
            }
            self::assertIsArray($count);
            $white = 'white' === $count['side'];
            $left = (string) \count(array_filter($board, static fn (string $p): bool => $white === ctype_upper($p)));
            self::assertSame([$left], $count['expected']);
            self::assertIsArray($count['choices']);
            self::assertCount(VisionRules::COUNTER_CHOICES, $count['choices']);
            self::assertContains($left, $count['choices']);
        }
    }
}
