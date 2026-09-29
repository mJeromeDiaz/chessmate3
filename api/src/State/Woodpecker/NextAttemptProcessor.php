<?php

declare(strict_types=1);

namespace App\State\Woodpecker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Woodpecker\Attempt;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Woodpecker\Cycle\CycleRunner;
use App\Woodpecker\Exception\SetNotFoundException;
use App\Woodpecker\Exception\SetNotPlayableException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /woodpecker/sets/{setId}/attempts: the next puzzle of the current run (or the pending one).
 * 409 when the set is paused, closed or resting (the set view tells which).
 *
 * @implements ProcessorInterface<mixed, Attempt>
 */
final class NextAttemptProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly CycleRunner $runner,
        private readonly SetViewFactory $views,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $woodpeckerAttemptStartLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Attempt
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->woodpeckerAttemptStartLimiter, $user->getId()->toRfc4122());
        $id = $uriVariables['setId'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }

        try {
            $attempt = $this->runner->next($user, Uuid::fromString($id));
        } catch (SetNotFoundException) {
            throw new NotFoundHttpException('Set not found.');
        } catch (SetNotPlayableException $e) {
            throw new ConflictHttpException($e->getMessage());
        }

        return Attempt::from($attempt, $this->views->create($attempt->getCycle()->getSet()));
    }
}
