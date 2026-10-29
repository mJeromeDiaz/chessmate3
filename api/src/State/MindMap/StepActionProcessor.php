<?php

declare(strict_types=1);

namespace App\State\MindMap;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\MindMap\Path;
use App\ApiResource\MindMap\StepAction;
use App\ApiResource\Training\Run;
use App\MindMap\Progress\PathReader;
use App\MindMap\Progress\StepLauncher;
use App\MindMap\Progress\StepNotFoundException;
use App\MindMap\Progress\StepRefusedException;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Exception\InvalidRunConfigException;
use App\Training\Exception\RunInProgressException;
use App\Training\Exception\SubjectNotFoundException;
use App\Training\Exception\SubjectUnavailableException;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /mind-map/{capacity}/steps/{key}/start and /skip (docs/MIND_MAP.md, § 3 and 4), within the
 * budget of the runs' starts.
 *
 * @implements ProcessorInterface<mixed, StepAction>
 */
final class StepActionProcessor implements ProcessorInterface
{
    use CapacityTrait;

    public function __construct(
        private readonly StepLauncher $launcher,
        private readonly PathReader $reader,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $trainingRunStartLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): StepAction
    {
        $capacity = self::capacity($uriVariables);
        $key = $uriVariables['key'] ?? null;
        if (!\is_string($key)) {
            throw new NotFoundHttpException('Step not found.');
        }
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->trainingRunStartLimiter, $user->getId()->toRfc4122());

        $action = new StepAction();
        $action->capacity = $capacity->value;
        $action->key = $key;
        try {
            if ('mind_map_step_skip' === $operation->getName()) {
                $this->launcher->skip($user, $capacity, $key);
                $action->path = Path::of($this->reader->read($user, $capacity));
            } else {
                $action->run = Run::from($this->launcher->start($user, $capacity, $key), $this->clock->now());
            }
        } catch (StepNotFoundException) {
            throw new NotFoundHttpException('Step not found.');
        } catch (StepRefusedException $e) {
            throw new ConflictHttpException($e->reason);
        } catch (RunInProgressException) {
            throw new ConflictHttpException('run_in_progress');
        } catch (InvalidRunConfigException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        } catch (SubjectNotFoundException) {
            throw new NotFoundHttpException('Subject not found.');
        } catch (SubjectUnavailableException $e) {
            throw new ConflictHttpException(\sprintf('%s (%s)', $e->getMessage(), $e->reason->value));
        }

        return $action;
    }
}
