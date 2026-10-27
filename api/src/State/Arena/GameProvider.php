<?php

declare(strict_types=1);

namespace App\State\Arena;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Arena\Game;
use App\Arena\Game\Games;
use App\Security\AuthenticatedUser;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * GET /arena/games/current and /arena/games/{id}: the current player's game, closed first when it
 * ended by itself (its clock, an abandonment); 404 without one, or for another player's.
 *
 * @implements ProviderInterface<Game>
 */
final class GameProvider implements ProviderInterface
{
    public function __construct(
        private readonly Games $games,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly ClockInterface $clock,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Game
    {
        $user = $this->authenticatedUser->get();
        $now = $this->clock->now();
        if ('arena_game_current' === $operation->getName()) {
            $game = $this->games->current($user, $now);
        } else {
            $id = $uriVariables['id'] ?? null;
            $game = \is_string($id) && Uuid::isValid($id) ? $this->games->find($user, Uuid::fromString($id), $now) : null;
        }
        if (null === $game) {
            throw new NotFoundHttpException('arena_game_not_found');
        }

        return Game::of($game, $now);
    }
}
