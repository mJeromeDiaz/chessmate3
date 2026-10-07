<?php

declare(strict_types=1);

namespace App\State\Woodpecker;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Puzzle\SubmitAttemptInput;
use App\ApiResource\Woodpecker\Attempt;
use App\Gamification\Xp\ExerciseXp;
use App\Puzzle\Attempt\Submission;
use App\Puzzle\Solution\InvalidSubmissionException;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Run\TimeboxRunner;
use App\Woodpecker\Cycle\CycleRunner;
use App\Woodpecker\Exception\AttemptAlreadySubmittedException;
use App\Woodpecker\Exception\AttemptNotFoundException;
use App\Woodpecker\Exception\CycleClosedException;
use App\Woodpecker\Exception\SetNotPlayableException;
use App\Puzzle\Catalog\PuzzleCatalog;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /woodpecker/attempts/{id}/submission. 404 for another user's or an unknown attempt, 409
 * when already submitted or its run is over (lost, completed, set paused) or it belongs to a
 * timed run, 400 for an impossible move log.
 *
 * @implements ProcessorInterface<SubmitAttemptInput, Attempt>
 */
final class SubmitAttemptProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly PuzzleCatalog $catalog,
        private readonly CycleRunner $runner,
        private readonly SetViewFactory $views,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $woodpeckerAttemptSubmitLimiter,
        private readonly TimeboxRunner $timebox,
        private readonly ExerciseXp $exerciseXp,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Attempt
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->woodpeckerAttemptSubmitLimiter, $user->getId()->toRfc4122());
        // A timed run past its time must not keep holding the set.
        $this->timebox->closeExpired($user);
        $this->exerciseXp->reset();
        $id = $uriVariables['id'] ?? null;
        if (!\is_string($id) || !Uuid::isValid($id)) {
            throw new NotFoundHttpException();
        }

        try {
            $attempt = $this->runner->submit($user, Uuid::fromString($id), new Submission($data->moves, $data->hintLevel, $data->solutionShown));
        } catch (AttemptNotFoundException) {
            throw new NotFoundHttpException('Attempt not found.');
        } catch (AttemptAlreadySubmittedException) {
            throw new ConflictHttpException('Attempt already submitted.');
        } catch (CycleClosedException) {
            throw new ConflictHttpException('This cycle run is over.');
        } catch (SetNotPlayableException $e) {
            throw new ConflictHttpException($e->getMessage());
        } catch (InvalidSubmissionException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        $view = Attempt::from($attempt, $this->catalog->get($attempt->getPuzzleId()), $this->views->create($attempt->getCycle()->getSet()));
        $view->xp = $this->exerciseXp->gained();

        return $view;
    }
}
