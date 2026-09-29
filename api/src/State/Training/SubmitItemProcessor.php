<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Training\RunStep;
use App\ApiResource\Training\SubmitItemInput;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Exception\InvalidItemSubmissionException;
use App\Training\Exception\ItemAlreadySubmittedException;
use App\Training\Exception\ItemClosedException;
use App\Training\Exception\ItemNotFoundException;
use App\Training\Exception\RunClosedException;
use App\Training\Exception\RunNotFoundException;
use App\Training\Exception\SubmissionTooLateException;
use App\Training\Module\ItemSubmission;
use App\Training\Run\TimeboxRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /training/runs/{id}/submission. 404 for an unknown run or item, 409 when the run is over
 * (closed, or time up when the submission arrived), the item already submitted or no longer
 * submittable (its classic cycle run was lost: ask for the next one), 400 for an impossible move
 * log.
 *
 * @implements ProcessorInterface<SubmitItemInput, RunStep>
 */
final class SubmitItemProcessor implements ProcessorInterface
{
    use RunIdTrait;

    public function __construct(
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $trainingItemSubmitLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RunStep
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->trainingItemSubmitLimiter, $user->getId()->toRfc4122());

        try {
            $step = $this->runner->submit($user, self::runId($uriVariables), new ItemSubmission($data->itemId, $data->moves, $data->hintLevel, $data->solutionShown));
        } catch (RunNotFoundException) {
            throw new NotFoundHttpException('Run not found.');
        } catch (ItemNotFoundException) {
            throw new NotFoundHttpException('Item not found in this run.');
        } catch (RunClosedException) {
            throw new ConflictHttpException('This run is over.');
        } catch (SubmissionTooLateException) {
            throw new ConflictHttpException('Time was up.');
        } catch (ItemAlreadySubmittedException) {
            throw new ConflictHttpException('Item already submitted.');
        } catch (ItemClosedException) {
            throw new ConflictHttpException('This item can no longer be submitted; ask for the next one.');
        } catch (InvalidItemSubmissionException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        return RunStep::from($step, $this->clock->now());
    }
}
