<?php

declare(strict_types=1);

namespace App\State\Endgame;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\ApiResource\Endgame\Overview;
use App\Endgame\EndgameRules;
use App\Endgame\Lesson\Catalogue;
use App\Endgame\Pool\PositionPool;
use App\Enum\Endgame\Goal;
use App\Puzzle\Rating\RatingCalculator;
use App\Repository\Endgame\AttemptRepository;
use App\Repository\Puzzle\RatingRepository;
use App\Security\AuthenticatedUser;
use App\Training\Run\TimeboxRunner;

/**
 * GET /endgames: the current user's only. A run left behind is closed first (lazy closing), so
 * that its positions count.
 *
 * @implements ProviderInterface<Overview>
 */
final class OverviewProvider implements ProviderInterface
{
    public function __construct(
        private readonly AttemptRepository $attempts,
        private readonly PositionPool $pool,
        private readonly RatingRepository $ratings,
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): Overview
    {
        $user = $this->authenticatedUser->get();
        $this->runner->closeExpired($user);

        $variants = $this->pool->variantCounts();
        $results = $this->attempts->resultsByLesson($user);
        $view = new Overview();
        $view->rules = EndgameRules::toArray();
        $view->elo = EndgameRules::proposedElo($this->ratings->findOneByUser($user)?->getRating() ?? RatingCalculator::DEFAULT_RATING);
        foreach (Catalogue::all() as $lesson) {
            $result = $results[$lesson->key] ?? null;
            $view->lessons[] = [
                'key' => $lesson->key,
                'family' => $lesson->family->value,
                'goal' => $lesson->goal->value,
                'result' => $lesson->result->value,
                'holdMoves' => Goal::Hold === $lesson->goal ? $lesson->holdMoves : null,
                'variants' => $variants[$lesson->key] ?? 0,
                'played' => $result['played'] ?? 0,
                'succeeded' => $result['succeeded'] ?? 0,
                'lastPlayedAt' => null === $result ? null : $result['lastPlayedAt']->format(\DATE_ATOM),
            ];
        }

        return $view;
    }
}
