<?php

declare(strict_types=1);

namespace App\Entity\Arena;

use App\Arena\ArenaRules;
use App\Entity\User;
use App\Enum\Arena\GameEnd;
use App\Enum\Arena\GameStatus;
use App\Enum\Repertoire\Color;
use App\Repository\Arena\GameRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;

/**
 * A game against a boss of the arena (docs/ARENA.md, § 3): its moves in UCI from the initial
 * position, both sides' clocks (milliseconds left when the side to move started thinking), the
 * moment that side started (milliseconds after the start, kept to the second), and its end. The clocks are the server's: the
 * player's runs out (2 s network tolerance), the boss's never does.
 */
#[ORM\Entity(repositoryClass: GameRepository::class)]
#[ORM\Table(name: 'arena_game')]
#[ORM\Index(name: 'idx_arena_game_user_status', columns: ['user_id', 'status'])]
#[ORM\Index(name: 'idx_arena_game_user_boss', columns: ['user_id', 'boss', 'status'])]
class Game
{
    #[ORM\Id]
    #[ORM\Column(type: 'uuid', unique: true)]
    private Uuid $id;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private User $user;

    /** The boss's character key. */
    #[ORM\Column(length: 32, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $boss;

    /** The player's side. */
    #[ORM\Column(length: 5, enumType: Color::class)]
    private Color $color;

    /** The boss's strength when the game started (its card's). */
    #[ORM\Column(type: Types::SMALLINT, options: ['unsigned' => true])]
    private int $elo;

    #[ORM\Column(length: 8, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $cadence;

    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $incrementMs;

    /** UCI moves separated by spaces. */
    #[ORM\Column(type: Types::TEXT, options: ['charset' => 'ascii', 'collation' => 'ascii_bin'])]
    private string $moves = '';

    #[ORM\Column(type: Types::INTEGER)]
    private int $playerMs;

    #[ORM\Column(type: Types::INTEGER)]
    private int $bossMs;

    /** When the side to move started thinking, milliseconds after $startedAt. */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true])]
    private int $turnStartedMs = 0;

    #[ORM\Column(length: 8, enumType: GameStatus::class)]
    private GameStatus $status = GameStatus::Active;

    #[ORM\Column(name: 'end_reason', length: 24, nullable: true, enumType: GameEnd::class)]
    private ?GameEnd $end = null;

    /** Paid at its end: the boss's first defeat in the arena. */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $crystals = 0;

    /** Gained at its end, within the daily cap (what the player was told). */
    #[ORM\Column(type: Types::INTEGER, options: ['unsigned' => true, 'default' => 0])]
    private int $xp = 0;

    #[ORM\Column]
    private \DateTimeImmutable $startedAt;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $endedAt = null;

    /**
     * @param array{0: int, 1: int} $control base seconds and increment
     */
    public function __construct(User $user, string $boss, Color $color, int $elo, string $cadence, array $control, \DateTimeImmutable $now)
    {
        $this->id = Uuid::v7();
        $this->user = $user;
        $this->boss = $boss;
        $this->color = $color;
        $this->elo = $elo;
        $this->cadence = $cadence;
        $this->playerMs = $control[0] * 1000;
        $this->bossMs = $control[0] * 1000;
        $this->incrementMs = $control[1] * 1000;
        // Stored to the second: the clocks count from it.
        $this->startedAt = $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
        $this->turnStartedMs = self::ms($now) - self::ms($this->startedAt);
    }

    /** Epoch milliseconds of an instant. */
    public static function ms(\DateTimeImmutable $at): int
    {
        return (int) $at->format('Uv');
    }

    /**
     * Plays a move already checked legal, on the clock of the side to move: its thinking time is
     * taken from it (the first move of each side is free), then its increment added.
     *
     * @return bool false when the player's clock ran out before the move (nothing is played)
     */
    public function play(string $uci, \DateTimeImmutable $now): bool
    {
        $elapsed = max(0, self::ms($now) - $this->turnStarted());
        if ($this->ply() >= ArenaRules::CLOCK_FROM_PLY) {
            if ($this->isPlayerTurn()) {
                if ($elapsed > $this->playerMs + ArenaRules::NETWORK_TOLERANCE_MS) {
                    return false;
                }
                $this->playerMs = max(0, $this->playerMs - $elapsed) + $this->incrementMs;
            } else {
                // The boss's clock stops at 0: a slow device never loses it the game.
                $this->bossMs = max(0, $this->bossMs - $elapsed) + $this->incrementMs;
            }
        }
        $this->moves = '' === $this->moves ? $uci : $this->moves.' '.$uci;
        $this->turnStartedMs = max(0, self::ms($now) - self::ms($this->startedAt));

        return true;
    }

    /**
     * How the game ends by itself at $now, null while it goes on: the player's clock run out on
     * their move, or the boss's move never received.
     */
    public function expiry(\DateTimeImmutable $now): ?GameEnd
    {
        if (GameStatus::Active !== $this->status) {
            return null;
        }
        $elapsed = self::ms($now) - $this->turnStarted();
        if (!$this->isPlayerTurn()) {
            return $elapsed > ArenaRules::ABANDON_SECONDS * 1000 ? GameEnd::Abandonment : null;
        }

        return $this->ply() >= ArenaRules::CLOCK_FROM_PLY && $elapsed > $this->playerMs + ArenaRules::NETWORK_TOLERANCE_MS ? GameEnd::Timeout : null;
    }

    public function finish(GameStatus $status, GameEnd $end, \DateTimeImmutable $now): void
    {
        $this->status = $status;
        $this->end = $end;
        $this->endedAt = $now;
        if (GameEnd::Timeout === $end) {
            $this->playerMs = 0;
        }
    }

    public function reward(int $crystals, int $xp): void
    {
        $this->crystals = $crystals;
        $this->xp = $xp;
    }

    /**
     * The clocks as they stand at $now: the side to move's thinking time taken off (once the
     * clocks run).
     *
     * @return array{player: int, boss: int}
     */
    public function clocksAt(\DateTimeImmutable $now): array
    {
        $clocks = ['player' => $this->playerMs, 'boss' => $this->bossMs];
        if (GameStatus::Active === $this->status && $this->ply() >= ArenaRules::CLOCK_FROM_PLY) {
            $side = $this->isPlayerTurn() ? 'player' : 'boss';
            $clocks[$side] = max(0, $clocks[$side] - max(0, self::ms($now) - $this->turnStarted()));
        }

        return $clocks;
    }

    public function isPlayerTurn(): bool
    {
        return (0 === $this->ply() % 2) === (Color::White === $this->color);
    }

    public function ply(): int
    {
        return '' === $this->moves ? 0 : substr_count($this->moves, ' ') + 1;
    }

    /**
     * @return list<string>
     */
    public function getMoves(): array
    {
        return '' === $this->moves ? [] : explode(' ', $this->moves);
    }

    public function isActive(): bool
    {
        return GameStatus::Active === $this->status;
    }

    public function getId(): Uuid
    {
        return $this->id;
    }

    public function getUser(): User
    {
        return $this->user;
    }

    public function getBoss(): string
    {
        return $this->boss;
    }

    public function getColor(): Color
    {
        return $this->color;
    }

    public function getElo(): int
    {
        return $this->elo;
    }

    public function getCadence(): string
    {
        return $this->cadence;
    }

    public function getIncrementMs(): int
    {
        return $this->incrementMs;
    }

    public function getStatus(): GameStatus
    {
        return $this->status;
    }

    public function getEnd(): ?GameEnd
    {
        return $this->end;
    }

    public function getCrystals(): int
    {
        return $this->crystals;
    }

    public function getXp(): int
    {
        return $this->xp;
    }

    public function getStartedAt(): \DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function getEndedAt(): ?\DateTimeImmutable
    {
        return $this->endedAt;
    }

    /** When the side to move started thinking, epoch milliseconds. */
    private function turnStarted(): int
    {
        return self::ms($this->startedAt) + $this->turnStartedMs;
    }
}
