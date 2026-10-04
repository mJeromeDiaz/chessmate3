<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Training\CreateSessionInput;
use App\ApiResource\Training\Session;
use App\Enum\Training\Module;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Exception\InvalidSessionException;
use App\Training\Exception\PlanNotFoundException;
use App\Training\Exception\SessionInProgressException;
use App\Training\Plan\PlanManager;
use App\Training\Session\SessionManager;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /training/sessions, and POST /training/plans/{id}/launch (from a saved session). 422 when a
 * step is invalid or cannot be played now (its number in the message), 409 while another session is
 * active (the client offers to resume or abandon it), 404 for an unknown saved session.
 *
 * @implements ProcessorInterface<CreateSessionInput|null, Session>
 */
final class CreateSessionProcessor implements ProcessorInterface
{
    use PlanIdTrait;

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly PlanManager $plans,
        private readonly SessionViewFactory $views,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $trainingSessionStartLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Session
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->trainingSessionStartLimiter, $user->getId()->toRfc4122());

        if ('training_plan_launch' === $operation->getName()) {
            try {
                return $this->views->view($this->plans->launch($user, self::planId($uriVariables)));
            } catch (PlanNotFoundException) {
                throw new NotFoundHttpException('Saved session not found.');
            } catch (InvalidSessionException $e) {
                throw new UnprocessableEntityHttpException($e->getMessage());
            } catch (SessionInProgressException) {
                throw new ConflictHttpException('Another training session is in progress.');
            }
        }
        if (!$data instanceof CreateSessionInput) {
            throw new \LogicException('A session input is expected.');
        }

        $steps = array_map(static fn ($step): array => [
            'module' => Module::from($step->module),
            'minutes' => $step->minutes,
            'notes' => $step->notes,
            'settings' => $step->settings,
        ], $data->steps);

        try {
            $session = $this->sessions->create($user, trim($data->title), trim($data->description), $steps);
        } catch (InvalidSessionException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        } catch (SessionInProgressException) {
            throw new ConflictHttpException('Another training session is in progress.');
        }

        return $this->views->view($session);
    }
}
