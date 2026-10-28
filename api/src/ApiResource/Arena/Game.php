<?php

declare(strict_types=1);

namespace App\ApiResource\Arena;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\OpenApi\Model\Operation;
use App\ApiResource\Woodpecker\Set;
use App\Arena\ArenaRules;
use App\Arena\Roster\Roster;
use App\Entity\Arena\Game as GameEntity;
use App\State\Arena\GameProcessor;
use App\State\Arena\GameProvider;

/**
 * A game against a boss of the arena (docs/ARENA.md, § 3 and 4), as it stands now (the clock of the
 * side to move already running down). `moves`: the moves of both sides, the boss's computed by the
 * client and sent like the player's. Refusals: 404 `arena_unknown_boss`, `arena_game_not_found`;
 * 409 `arena_boss_locked`, `arena_game_in_progress`, `arena_game_over`, `arena_out_of_sync`; 422
 * `arena_illegal_move`.
 */
#[ApiResource(
    shortName: 'ArenaGame',
    normalizationContext: ['skip_null_values' => false],
    operations: [
        new Get(uriTemplate: '/arena/games/{id}', requirements: ['id' => Set::UUID_PATTERN], name: 'arena_game_get', provider: GameProvider::class),
        new Get(
            uriTemplate: '/arena/games/current',
            openapi: new Operation(summary: 'The game in progress (404 without one).'),
            name: 'arena_game_current',
            provider: GameProvider::class,
        ),
        new Post(
            uriTemplate: '/arena/games',
            status: 201,
            openapi: new Operation(summary: 'Starts a game against a boss the player can challenge.'),
            input: StartGameInput::class,
            read: false,
            name: 'arena_game_start',
            processor: GameProcessor::class,
        ),
        new Post(
            uriTemplate: '/arena/games/{id}/moves',
            requirements: ['id' => Set::UUID_PATTERN],
            status: 200,
            openapi: new Operation(summary: 'Plays the move of the side to move.'),
            input: MoveInput::class,
            read: false,
            name: 'arena_game_move',
            processor: GameProcessor::class,
        ),
        new Post(uriTemplate: '/arena/games/{id}/resign', requirements: ['id' => Set::UUID_PATTERN], status: 200, input: false, read: false, deserialize: false, name: 'arena_game_resign', processor: GameProcessor::class),
        new Post(uriTemplate: '/arena/games/{id}/abort', requirements: ['id' => Set::UUID_PATTERN], status: 200, input: false, read: false, deserialize: false, name: 'arena_game_abort', processor: GameProcessor::class),
        new Post(uriTemplate: '/arena/games/{id}/restart', requirements: ['id' => Set::UUID_PATTERN], status: 201, input: false, read: false, deserialize: false, name: 'arena_game_restart', processor: GameProcessor::class),
    ],
)]
final class Game
{
    #[ApiProperty(identifier: true)]
    public string $id;
    public string $boss;
    /** The player's side. */
    public string $color;
    public int $elo;
    /** Its strength in the opening (Aaron), null: its Elo. */
    public ?int $openingElo = null;
    public ?int $endgameElo = null;
    /** @var list<string> */
    public array $styles = [];
    public bool $guarded = false;
    public string $cadence;
    public int $incrementMs;
    /** @var list<string> UCI */
    public array $moves = [];
    /** Milliseconds left now. */
    public int $playerMs;
    public int $bossMs;
    /** player or boss. */
    public string $turn;
    /** The clocks run from the second move of each side. */
    public bool $clocksRunning;
    public string $status;
    public ?string $end = null;
    public int $crystals = 0;
    public int $xp = 0;
    public string $startedAt;
    public ?string $endedAt = null;

    public static function of(GameEntity $game, \DateTimeImmutable $now): self
    {
        $boss = Roster::find($game->getBoss());
        $clocks = $game->clocksAt($now);
        $view = new self();
        $view->id = $game->getId()->toRfc4122();
        $view->boss = $game->getBoss();
        $view->color = $game->getColor()->value;
        $view->elo = $game->getElo();
        $view->openingElo = $boss?->openingElo;
        $view->endgameElo = $boss?->endgameElo;
        $view->styles = $boss->styles ?? [];
        $view->guarded = $boss->guarded ?? false;
        $view->cadence = $game->getCadence();
        $view->incrementMs = $game->getIncrementMs();
        $view->moves = $game->getMoves();
        $view->playerMs = $clocks['player'];
        $view->bossMs = $clocks['boss'];
        $view->turn = $game->isPlayerTurn() ? 'player' : 'boss';
        $view->clocksRunning = $game->isActive() && $game->ply() >= ArenaRules::CLOCK_FROM_PLY;
        $view->status = $game->getStatus()->value;
        $view->end = $game->getEnd()?->value;
        $view->crystals = $game->getCrystals();
        $view->xp = $game->getXp();
        $view->startedAt = $game->getStartedAt()->format(\DATE_ATOM);
        $view->endedAt = $game->getEndedAt()?->format(\DATE_ATOM);

        return $view;
    }
}
