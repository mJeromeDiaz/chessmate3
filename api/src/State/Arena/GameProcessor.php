<?php

declare(strict_types=1);

namespace App\State\Arena;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Arena\Game;
use App\ApiResource\Arena\MoveInput;
use App\ApiResource\Arena\StartGameInput;
use App\Arena\Game\GameRefusal;
use App\Arena\Game\Games;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POST /arena/games (start), /arena/games/{id}/moves, /resign, /abort and /restart (docs/ARENA.md,
 * § 4): a refusal becomes its status, its value as the message. Starting within the budget of the
 * games started, the others within the moves'.
 *
 * @implements ProcessorInterface<StartGameInput|MoveInput|null, Game>
 */
final class GameProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly Games $games,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $arenaGameStartLimiter,
        private readonly RateLimiterFactoryInterface $arenaGameWriteLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Game
    {
        $user = $this->authenticatedUser->get();
        $name = $operation->getName();
        $starts = \in_array($name, ['arena_game_start', 'arena_game_restart'], true);
        $this->rateLimitGuard->consume($starts ? $this->arenaGameStartLimiter : $this->arenaGameWriteLimiter, $user->getId()->toRfc4122());
        $now = $this->clock->now();

        if ('arena_game_start' === $name) {
            if (!$data instanceof StartGameInput) {
                throw new UnprocessableEntityHttpException('A boss, a side and a time control are expected.');
            }
            $result = $this->games->start($user, $data->boss, $data->color, $data->cadence, $now);
        } else {
            $id = $uriVariables['id'] ?? null;
            if (!\is_string($id) || !Uuid::isValid($id)) {
                throw new NotFoundHttpException(GameRefusal::NotFound->value);
            }
            $id = Uuid::fromString($id);
            $result = match ($name) {
                'arena_game_move' => $data instanceof MoveInput
                    ? $this->games->move($user, $id, $data->ply, $data->move, $now)
                    : throw new UnprocessableEntityHttpException('A move is expected.'),
                'arena_game_resign' => $this->games->resign($user, $id, $now),
                'arena_game_abort' => $this->games->abort($user, $id, $now),
                default => $this->games->restart($user, $id, $now),
            };
        }

        if ($result instanceof GameRefusal) {
            throw match ($result) {
                GameRefusal::UnknownBoss, GameRefusal::NotFound => new NotFoundHttpException($result->value),
                GameRefusal::Illegal => new UnprocessableEntityHttpException($result->value),
                default => new ConflictHttpException($result->value),
            };
        }

        return Game::of($result, $now);
    }
}
