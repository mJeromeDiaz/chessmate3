<?php

declare(strict_types=1);

namespace App\State\Puzzle;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Puzzle\Attempt;
use App\ApiResource\Puzzle\StartAttemptInput;
use App\Puzzle\Attempt\AttemptService;
use App\Puzzle\Attempt\Exception\NoPuzzleAvailableException;
use App\Puzzle\Attempt\Exception\ReplayNotAllowedException;
use App\Puzzle\Selection\SelectionCriteria;
use App\Repository\Puzzle\PuzzleRepository;
use App\Repository\Puzzle\ThemeRepository;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /puzzles/attempts: the next rated puzzle (or the pending one), or an unrated replay.
 *
 * @implements ProcessorInterface<StartAttemptInput, Attempt>
 */
final class StartAttemptProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AttemptService $attempts,
        private readonly ThemeRepository $themes,
        private readonly PuzzleRepository $puzzles,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $puzzleAttemptStartLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Attempt
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->puzzleAttemptStartLimiter, $user->getId()->toRfc4122());

        if (null !== $data->replayOf) {
            $puzzle = $this->puzzles->findOneByLichessId($data->replayOf);
            try {
                if (null === $puzzle) {
                    throw new ReplayNotAllowedException();
                }

                return Attempt::from($this->attempts->replay($user, $puzzle));
            } catch (ReplayNotAllowedException) {
                throw new NotFoundHttpException('Puzzle not found in your history.');
            }
        }

        $keys = array_values(array_unique($data->themes));
        $themes = $this->themes->findByKeys($keys);
        if (\count($themes) !== \count($keys)) {
            throw new UnprocessableEntityHttpException('Unknown theme.');
        }

        try {
            $attempt = $this->attempts->start($user, new SelectionCriteria(
                array_map(static fn ($theme): int => (int) $theme->getId(), $themes),
                $data->difficulty,
            ));
        } catch (NoPuzzleAvailableException) {
            throw new NotFoundHttpException('No puzzle available for these criteria.');
        }

        return Attempt::from($attempt);
    }
}
