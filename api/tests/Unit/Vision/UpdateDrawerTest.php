<?php

declare(strict_types=1);

namespace App\Tests\Unit\Vision;

use App\Blindfold\Recall\PositionGenerator;
use App\Chess\Attacks;
use App\Chess\RandomLine;
use App\Chess\Rules;
use App\Enum\Vision\Ask;
use App\Enum\Vision\Kind;
use App\Vision\Board\EnPrise;
use App\Vision\Board\Reach;
use App\Vision\Drawing\UpdateDrawer;
use PHPUnit\Framework\TestCase;

/**
 * The questions on a position updated by moves (docs/VISION.md, § 2 ter): legal moves of both
 * sides from a legal position, answers consistent with the position they reach.
 */
final class UpdateDrawerTest extends TestCase
{
    public function testEachKindAndItsAnswers(): void
    {
        $drawer = new UpdateDrawer(new PositionGenerator(), new RandomLine());
        foreach ([Kind::UpdateMissing, Kind::UpdateCaptures, Kind::UpdateMixed, Kind::UpdateBoss] as $kind) {
            for ($i = 0; $i < 30; ++$i) {
                $draw = $drawer->draw($kind);
                $rules = Rules::fromFen($draw->fen);
                $taken = [];
                foreach ($draw->announced as $uci) {
                    $there = Attacks::board($rules->fen())[substr($uci, 2, 2)] ?? null;
                    self::assertNotNull($rules->playUci($uci), $kind->value.' '.$uci);
                    if (null !== $there) {
                        $taken[substr($uci, 2, 2)] = $there;
                    }
                }
                $final = $rules->fen();
                $board = Attacks::board($final);
                switch ($kind) {
                    case Kind::UpdateMissing:
                        self::assertCount(1, $taken);
                        self::assertSame([Ask::Missing, array_values($taken)], [$draw->ask, $draw->expected]);
                        break;
                    case Kind::UpdateCaptures:
                        self::assertCount(2, $taken, 'Two captures, on two squares.');
                        $squares = array_keys($taken);
                        sort($squares);
                        self::assertSame([Ask::Captures, $squares], [$draw->ask, $draw->expected]);
                        break;
                    case Kind::UpdateMixed:
                        self::assertGreaterThanOrEqual(5, \count($draw->announced));
                        self::assertLessThanOrEqual(8, \count($draw->announced));
                        self::assertSame(self::answer($draw->ask, (string) $draw->target, $final, $draw->params), $draw->expected);
                        break;
                    default:
                        self::assertCount(5, $draw->announced);
                        self::assertContains($draw->ask, [Ask::What, Ask::Where]);
                        self::assertSame(self::answer($draw->ask, (string) $draw->target, $final, []), $draw->expected);
                        $followUps = $draw->params['followUps'] ?? null;
                        self::assertIsArray($followUps);
                        self::assertSame([Ask::Where === $draw->ask ? 'what' : 'where', 'en_prise'], array_column($followUps, 'ask'));
                        $last = $followUps[1] ?? null;
                        self::assertIsArray($last);
                        self::assertSame(EnPrise::squares($final), $last['expected'] ?? null);
                }
                self::assertNotEmpty($board);
            }
        }
    }

    /**
     * The right answer to a question about $fen.
     *
     * @param array<string, mixed> $params
     *
     * @return list<string>
     */
    private static function answer(Ask $ask, string $target, string $fen, array $params): array
    {
        $board = Attacks::board($fen);

        return match ($ask) {
            Ask::What => [$board[$target] ?? ''],
            Ask::Where => array_keys(array_filter($board, static fn (string $piece): bool => $piece === $target)),
            Ask::Reach => [Reach::canReach($fen, \is_string($params['from'] ?? null) ? $params['from'] : '', $target) ? 'yes' : 'no'],
            default => [],
        };
    }
}
