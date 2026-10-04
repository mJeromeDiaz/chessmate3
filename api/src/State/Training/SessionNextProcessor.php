<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Training\Run;
use App\ApiResource\Training\SessionLaunch;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\Training\Exception\RunInProgressException;
use App\Training\Exception\SessionClosedException;
use App\Training\Exception\SessionNotFoundException;
use App\Training\Exception\StepBlockedException;
use App\Training\Session\SessionManager;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /training/sessions/{id}/next: starts the current step. 409 when the step cannot start (the
 * session then holds the reason), when another run is in progress, or once the session is over.
 *
 * @implements ProcessorInterface<null, SessionLaunch>
 */
final class SessionNextProcessor implements ProcessorInterface
{
    use SessionIdTrait;

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly SessionViewFactory $views,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $trainingRunStartLimiter,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): SessionLaunch
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->trainingRunStartLimiter, $user->getId()->toRfc4122());

        try {
            [$session, $run] = $this->sessions->next($user, self::sessionId($uriVariables));
        } catch (SessionNotFoundException) {
            throw new NotFoundHttpException('Session not found.');
        } catch (StepBlockedException $e) {
            throw new ConflictHttpException(sprintf('%s (%s)', $e->getMessage(), $e->reason));
        } catch (RunInProgressException) {
            throw new ConflictHttpException('Another training run is in progress.');
        } catch (SessionClosedException) {
            throw new ConflictHttpException('The session is over.');
        }

        $launch = new SessionLaunch();
        $launch->id = $session->getId()->toRfc4122();
        $launch->session = $this->views->view($session);
        $launch->run = Run::from($run, $this->clock->now());

        return $launch;
    }
}
