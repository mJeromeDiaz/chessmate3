<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Training\Run;
use App\ApiResource\Training\StartRunInput;
use App\Enum\Training\Module;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Exception\RunInProgressException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use App\Training\Run\TimeboxRunner;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /training/runs. 409 while another run is active (the client offers to resume or end it)
 * or when the subject cannot be played now (reason in the message), 404 for an unknown subject.
 *
 * @implements ProcessorInterface<StartRunInput, Run>
 */
final class StartRunProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TimeboxRunner $runner,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $trainingRunStartLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Run
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->trainingRunStartLimiter, $user->getId()->toRfc4122());

        try {
            $run = $this->runner->start($user, Module::from($data->module), Uuid::fromString($data->subjectId), $data->budgetSeconds, $data->config);
        } catch (RunInProgressException) {
            throw new ConflictHttpException('Another training run is in progress.');
        } catch (SubjectNotFoundException) {
            throw new NotFoundHttpException('Subject not found.');
        } catch (SubjectUnavailableException $e) {
            throw new ConflictHttpException(sprintf('%s (%s)', $e->getMessage(), $e->reason->value));
        }

        return Run::from($run, $this->clock->now());
    }
}
