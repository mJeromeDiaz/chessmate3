<?php

declare(strict_types=1);

namespace App\State\Puzzle;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Puzzle\Attempt;
use App\Puzzle\Attempt\Exception\AttemptNotFoundException;
use App\Puzzle\Catalog\PuzzleCatalog;
use App\Puzzle\Pardon\Pardoner;
use App\Puzzle\Pardon\PardonRefusedException;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Uid\Uuid;

/**
 * POST /puzzles/attempts/{id}/pardon (docs/PUZZLES.md, "Erreur pardonnée"): the attempt, pardoned,
 * with the pardons left. 404 for a foreign or unknown attempt, 409 when refused (`detail`:
 * `not_pardonable`, `too_late`, `no_pardon`).
 *
 * @implements ProcessorInterface<mixed, Attempt>
 */
final class PardonAttemptProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PuzzleCatalog $catalog,
        private readonly Pardoner $pardoner,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $puzzleAttemptSubmitLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Attempt
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->puzzleAttemptSubmitLimiter, $user->getId()->toRfc4122());
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }

        try {
            ['attempt' => $attempt, 'pardons' => $pardons] = $this->pardoner->pardon($user, Uuid::fromString($id));
        } catch (AttemptNotFoundException) {
            throw new NotFoundHttpException('Attempt not found.');
        } catch (PardonRefusedException $e) {
            throw new ConflictHttpException($e->reason);
        }

        $view = Attempt::from($attempt, $this->catalog->get($attempt->getPuzzleId()));
        $view->pardons = $pardons;

        return $view;
    }
}
