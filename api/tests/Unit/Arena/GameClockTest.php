<?php

declare(strict_types=1);

namespace App\Tests\Unit\Arena;

use App\Entity\Arena\Game;
use App\Entity\User;
use App\Enum\Arena\GameEnd;
use App\Enum\Repertoire\Color;
use PHPUnit\Framework\TestCase;

/**
 * The clocks of a game of the arena (docs/ARENA.md, § 3): kept by the server, the first move of
 * each side free, the increment added, the player's clock running out (2 s network tolerance), the
 * boss's never.
 */
final class GameClockTest extends TestCase
{
    private \DateTimeImmutable $start;

    protected function setUp(): void
    {
        $this->start = new \DateTimeImmutable('2026-10-27 10:00:00.250', new \DateTimeZone('UTC'));
    }

    public function testTheFirstMovesAreFreeThenThinkingTimeIsTakenAndTheIncrementAdded(): void
    {
        $game = $this->game(Color::White);
        self::assertTrue($game->isPlayerTurn());
        self::assertTrue($game->play('e2e4', $this->at(40_000)), 'the first move, however late');
        self::assertFalse($game->isPlayerTurn());
        self::assertTrue($game->play('e7e5', $this->at(41_000)));
        self::assertSame(['player' => 300_000, 'boss' => 300_000], $game->clocksAt($this->at(41_000)));
        self::assertSame(['player' => 290_000, 'boss' => 300_000], $game->clocksAt($this->at(51_000)), 'the player thinks: their clock runs down');
        self::assertTrue($game->play('g1f3', $this->at(51_000)));
        self::assertSame(['player' => 293_000, 'boss' => 300_000], $game->clocksAt($this->at(51_000)), '10 s taken, 3 s added');
        self::assertTrue($game->play('b8c6', $this->at(53_500)));
        self::assertSame(['player' => 293_000, 'boss' => 300_500], $game->clocksAt($this->at(53_500)));
        self::assertSame(['e2e4', 'e7e5', 'g1f3', 'b8c6'], $game->getMoves());
        self::assertSame(4, $game->ply());
    }

    public function testThePlayerLosesOnTimeAfterTheToleranceTheBossNever(): void
    {
        $game = $this->game(Color::Black, '3+2');
        self::assertFalse($game->isPlayerTurn(), 'the boss opens');
        $game->play('e2e4', $this->at(1_000));
        $game->play('e7e5', $this->at(2_000));
        // The boss thinks far too long: its clock stops at 0, then gets its increment.
        $game->play('g1f3', $this->at(400_000));
        self::assertSame(['player' => 180_000, 'boss' => 2_000], $game->clocksAt($this->at(400_000)));
        self::assertNull($game->expiry($this->at(400_000 + 181_999)), 'within the tolerance');
        self::assertSame(GameEnd::Timeout, $game->expiry($this->at(400_000 + 182_001)));
        self::assertTrue($game->play('b8c6', $this->at(400_000 + 181_500)), 'received within the tolerance');
        self::assertSame(2_000, $game->clocksAt($this->at(400_000 + 181_500))['player'], 'nothing left but the increment');

        $late = $this->game(Color::Black, '3+2');
        $late->play('e2e4', $this->at(1_000));
        $late->play('e7e5', $this->at(2_000));
        $late->play('g1f3', $this->at(3_000));
        self::assertFalse($late->play('b8c6', $this->at(3_000 + 182_001)), 'too late: not played');
        self::assertSame(3, $late->ply());
    }

    public function testAMissingBossMoveIsAnAbandonment(): void
    {
        $game = $this->game(Color::White);
        $game->play('e2e4', $this->at(1_000));
        self::assertNull($game->expiry($this->at(1_000 + 300_000)));
        self::assertSame(GameEnd::Abandonment, $game->expiry($this->at(1_000 + 300_001)));
        self::assertNull($this->game(Color::White)->expiry($this->at(3_600_000)), 'the player\'s first move: no clock yet');
    }

    private function game(Color $color, string $cadence = '5+3'): Game
    {
        $control = '3+2' === $cadence ? [180, 2] : [300, 3];

        return new Game(new User(), 'jongleur', $color, 1000, $cadence, $control, $this->start);
    }

    /** $ms after the game started (its start kept to the second). */
    private function at(int $ms): \DateTimeImmutable
    {
        return (new \DateTimeImmutable('2026-10-27 10:00:00', new \DateTimeZone('UTC')))->modify(\sprintf('+%d milliseconds', $ms));
    }
}
