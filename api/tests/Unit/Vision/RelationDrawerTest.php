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
use App\Vision\Board\Relations;
use App\Vision\Drawing\RelationDrawer;
use PHPUnit\Framework\TestCase;

/**
 * The questions on the relations between pieces (docs/VISION.md, § 2 quater): legal moves, then
 * every piece in the relation asked, never an empty answer; the mini-boss tells the pieces only
 * attacked from the winning captures.
 */
final class RelationDrawerTest extends TestCase
{
    public function testEachKindAndItsAnswers(): void
    {
        $drawer = new RelationDrawer(new PositionGenerator(), new RandomLine());
        foreach (Kind::cases() as $kind) {
            if (!str_starts_with($kind->value, 'rel_')) {
                continue;
            }
            for ($i = 0; $i < 30; ++$i) {
                $draw = $drawer->draw($kind);
                $rules = Rules::fromFen($draw->fen);
                foreach ($draw->announced as $uci) {
                    self::assertNotNull($rules->playUci($uci), $kind->value.' '.$uci);
                }
                $final = $rules->fen();
                self::assertNotSame([], $draw->expected, 'Never an empty answer.');
                $color = 'black' === $draw->side ? 'b' : 'w';
                $expected = match ($draw->ask) {
                    Ask::Attacked => Relations::attacked($final, $color),
                    Ask::Defended => Relations::defended($final, $color),
                    Ask::Undefended => Relations::undefended($final, $color),
                    Ask::Aligned => Relations::aligned($final, (string) array_search($draw->target, Attacks::board($final), true)),
                    Ask::EnPriseAll => EnPrise::squares($final),
                    default => null,
                };
                self::assertSame($expected, $draw->expected, $kind->value.' '.$draw->ask->value);
                if (Kind::RelationBoss === $kind) {
                    $followUps = $draw->params['followUps'] ?? null;
                    self::assertIsArray($followUps);
                    self::assertSame(['defended', 'winning'], array_column($followUps, 'ask'));
                    $winning = $followUps[1] ?? null;
                    self::assertIsArray($winning);
                    self::assertSame(EnPrise::squares($final), $winning['expected'] ?? null);
                    self::assertNotSame([], array_diff($draw->expected, EnPrise::squares($final)), 'A piece attacked, no winning capture.');
                }
            }
        }
    }
}
