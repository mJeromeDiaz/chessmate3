<?php

declare(strict_types=1);

namespace App\State\Puzzle;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Puzzle\Attempt;
use App\ApiResource\Puzzle\StartAttemptInput;
use App\Puzzle\Attempt\AttemptService;
use App\Puzzle\Attempt\Exception\AttemptHeldByRunException;
use App\Puzzle\Attempt\Exception\NoPuzzleAvailableException;
use App\Puzzle\Attempt\Exception\ReplayNotAllowedException;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Selection\SelectionCriteria;
use App\Repository\Catalog\PuzzleRepository;
use App\Repository\Catalog\ThemeRepository;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Run\TimeboxRunner;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
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
        private readonly PuzzleCatalog $catalog,
        private readonly AttemptService $attempts,
        private readonly ThemeRepository $themes,
        private readonly PuzzleRepository $puzzles,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $puzzleAttemptStartLimiter,
        private readonly TimeboxRunner $timebox,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Attempt
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->puzzleAttemptStartLimiter, $user->getId()->toRfc4122());
        // A timed run past its time must not keep holding the pending puzzle.
        $this->timebox->closeExpired($user);

        if (null !== $data->replayOf) {
            $puzzle = $this->puzzles->findOneByLichessId($data->replayOf);
            try {
                if (null === $puzzle) {
                    throw new ReplayNotAllowedException();
                }

                return Attempt::from($this->attempts->replay($user, $puzzle), $puzzle);
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
        } catch (AttemptHeldByRunException) {
            throw new ConflictHttpException('Your pending puzzle is being played in a timed run.');
        }

        return Attempt::from($attempt, $this->catalog->get($attempt->getPuzzleId()));
    }
}
