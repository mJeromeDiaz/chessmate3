<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vision;

use App\Blindfold\Recall\PositionGenerator;
use App\Chess\RandomLine;
use App\Chess\Rules;
use App\Vision\Drawing\GargoyleDrawer;
use App\Vision\Drawing\RelationDrawer;
use App\Vision\Drawing\UpdateDrawer;
use PHPUnit\Framework\TestCase;

/**
 * The Gargouille's positions (docs/VISION.md, § 2 quinquies): 5 legal moves, then 5 questions, each
 * of another kind, never an empty answer.
 */
final class GargoyleDrawerTest extends TestCase
{
    public function testFiveQuestionsOfFiveKinds(): void
    {
        $generator = new PositionGenerator();
        $lines = new RandomLine();
        $drawer = new GargoyleDrawer($generator, $lines, new UpdateDrawer($generator, $lines), new RelationDrawer($generator, $lines));
        for ($i = 0; $i < 40; ++$i) {
            $draw = $drawer->draw();
            self::assertCount(5, $draw->announced);
            $rules = Rules::fromFen($draw->fen);
            foreach ($draw->announced as $uci) {
                self::assertNotNull($rules->playUci($uci), $uci);
            }
            $followUps = $draw->params['followUps'] ?? null;
            self::assertIsArray($followUps);
            self::assertCount(4, $followUps);
            $asks = [$draw->ask->value];
            foreach ($followUps as $followUp) {
                self::assertIsArray($followUp);
                self::assertIsString($followUp['ask'] ?? null);
                $asks[] = $followUp['ask'];
            }
            self::assertSame($asks, array_values(array_unique($asks)), 'Each of another kind.');
            self::assertNotSame([], $draw->expected);
            foreach ($followUps as $followUp) {
                self::assertIsArray($followUp);
                self::assertIsString($followUp['ask'] ?? null);
                self::assertNotSame([], $followUp['expected'] ?? [], $followUp['ask']);
                if ('reach' === $followUp['ask']) {
                    self::assertIsString($followUp['piece'] ?? null, 'Its piece named.');
                }
            }
        }
    }
}
