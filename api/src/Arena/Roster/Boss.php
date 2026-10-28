<?php

declare(strict_types=1);

namespace App\Arena\Roster;

use App\Arena\ArenaRules;
use App\Enum\Arena\Tier;
use App\Enum\MindMap\Capacity;

/**
 * A boss as an opponent in the arena (docs/ARENA.md, § 2), met on a path or the arena's own: the
 * client plays its moves
 * (Maia at $elo, at $openingElo in the opening, at $endgameElo once in an endgame, its $styles
 * weighing Maia's moves, Stockfish keeping it from blundering when $guarded); the server only keeps
 * its settings. A professor (Tier::Master) is the arena's last level, whatever its strength.
 */
final readonly class Boss
{
    /**
     * @param string       $character  the paths' character key (mindMapPath.characters.<key>)
     * @param Capacity|null $capacity  the path where it is met, null: the arena's own boss
     * @param int          $elo        its strength shown on its card
     * @param int|null     $endgameElo its strength once in an endgame, null: the same
     * @param list<string> $styles     the moves it prefers (keys read by the client)
     * @param bool         $guarded    Stockfish replaces its blunders
     * @param bool         $forSale    the arena's own boss bought in the shop (a path's boss never is)
     * @param int|null     $openingElo its strength in the opening ({@see ArenaRules::OPENING_PLIES}), null: the same
     */
    public function __construct(
        public string $character,
        public ?Capacity $capacity,
        public Tier $tier,
        public int $elo,
        public ?int $endgameElo = null,
        public array $styles = [],
        public bool $guarded = false,
        public bool $forSale = false,
        public ?int $openingElo = null,
    ) {
    }

    /** Its level on the arena's page, by its strength; the professors': the last one. */
    public function level(): int
    {
        return Tier::Master === $this->tier ? ArenaRules::MASTER_LEVEL : ArenaRules::level($this->elo);
    }

    /** What its first defeat in the arena pays, by its level. */
    public function crystals(): int
    {
        return ArenaRules::crystals($this->level());
    }

    /** Its price in crystals, null when it is not sold. */
    public function price(): ?int
    {
        return $this->forSale ? ArenaRules::price($this->level()) : null;
    }
}
