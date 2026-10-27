<?php

declare(strict_types=1);

namespace App\Arena\Roster;

use App\Enum\Arena\Tier;
use App\Enum\MindMap\Capacity;

/**
 * A boss of the paths as an opponent in the arena (docs/ARENA.md, § 2): the client plays its moves
 * (Maia at $elo, at $endgameElo once in an endgame, its $styles weighing Maia's moves, Stockfish
 * keeping it from blundering when $guarded); the server only keeps its settings.
 */
final readonly class Boss
{
    /**
     * @param string       $character  the paths' character key (mindMapPath.characters.<key>)
     * @param Capacity     $capacity   the path where it is met
     * @param int          $elo        its strength shown on its card
     * @param int|null     $endgameElo its strength once in an endgame, null: the same
     * @param list<string> $styles     the moves it prefers (keys read by the client)
     * @param bool         $guarded    Stockfish replaces its blunders
     */
    public function __construct(
        public string $character,
        public Capacity $capacity,
        public Tier $tier,
        public int $elo,
        public ?int $endgameElo = null,
        public array $styles = [],
        public bool $guarded = false,
    ) {
    }
}
