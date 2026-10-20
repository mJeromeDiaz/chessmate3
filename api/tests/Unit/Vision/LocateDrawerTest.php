<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vision;

use App\Blindfold\Recall\PositionGenerator;
use App\Chess\Attacks;
use App\Enum\Vision\Ask;
use App\Vision\Drawing\LocateDrawer;
use App\Vision\VisionRules;
use PHPUnit\Framework\TestCase;

/**
 * The `locate` questions (docs/VISION.md, § 2): a generated position of 8 pieces, « where is this
 * piece? » answered by every square holding it, « what stands on this square? » by its piece or ''.
 */
final class LocateDrawerTest extends TestCase
{
    public function testQuestionsAndTheirAnswers(): void
    {
        $drawer = new LocateDrawer(new PositionGenerator());
        $asks = [];
        for ($i = 0; $i < 60; ++$i) {
            $draw = $drawer->draw();
            $board = Attacks::board($draw->fen);
            self::assertCount(8, $board);
            self::assertSame([[], VisionRules::LOCATE_SECONDS, null], [$draw->announced, $draw->visibleSeconds, $draw->puzzleId]);
            $asks[$draw->ask->value] = true;
            if (Ask::Where === $draw->ask) {
                self::assertNotEmpty($draw->expected);
                foreach ($draw->expected as $square) {
                    self::assertSame($draw->target, $board[$square] ?? null);
                }
                self::assertCount(\count($draw->expected), array_filter($board, static fn (string $piece): bool => $piece === $draw->target));
            } else {
                self::assertSame(Ask::What, $draw->ask);
                self::assertSame([$board[(string) $draw->target] ?? ''], $draw->expected);
            }
        }
        self::assertCount(2, $asks, 'Both questions come up.');
    }
}
