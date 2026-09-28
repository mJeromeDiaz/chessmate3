<?php

declare(strict_types=1);

namespace App\State\Puzzle;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Puzzle\Attempt;
use App\ApiResource\Puzzle\SubmitAttemptInput;
use App\Puzzle\Attempt\AttemptService;
use App\Puzzle\Attempt\Exception\AttemptAlreadySubmittedException;
use App\Puzzle\Attempt\Exception\AttemptNotFoundException;
use App\Puzzle\Attempt\Submission;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /puzzles/attempts/{id}/submission. 404 for a foreign or unknown attempt, 409 when already
 * submitted, 400 for a move log no honest client could send.
 *
 * @implements ProcessorInterface<SubmitAttemptInput, Attempt>
 */
final class SubmitAttemptProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AttemptService $attempts,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $puzzleAttemptSubmitLimiter,
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
            $attempt = $this->attempts->submit($user, Uuid::fromString($id), new Submission(
                $data->moves,
                $data->hintLevel,
                $data->solutionShown,
            ));
        } catch (AttemptNotFoundException) {
            throw new NotFoundHttpException('Attempt not found.');
        } catch (AttemptAlreadySubmittedException) {
            throw new ConflictHttpException('Attempt already submitted.');
        } catch (InvalidSubmissionException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return Attempt::from($attempt);
    }
}
