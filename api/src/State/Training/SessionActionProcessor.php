<?php

declare(strict_types=1);

namespace App\State\Training;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Training\Session;
use App\Security\AuthenticatedUser;
use App\Training\Exception\SessionClosedException;
use App\Training\Exception\SessionNotFoundException;
use App\Training\Exception\StepRunningException;
use App\Training\Session\SessionManager;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /training/sessions/{id}/skip and /abandon. Skipping: 409 while the step's run is in
 * progress or once the session is over. Abandoning is idempotent.
 *
 * @implements ProcessorInterface<null, Session>
 */
final class SessionActionProcessor implements ProcessorInterface
{
    use SessionIdTrait;

    public function __construct(
        private readonly SessionManager $sessions,
        private readonly SessionViewFactory $views,
        private readonly AuthenticatedUser $authenticatedUser,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Session
    {
        $user = $this->authenticatedUser->get();
        $id = self::sessionId($uriVariables);

        try {
            $session = 'training_session_skip' === $operation->getName()
                ? $this->sessions->skip($user, $id)
                : $this->sessions->abandon($user, $id);
        } catch (SessionNotFoundException) {
            throw new NotFoundHttpException('Session not found.');
        } catch (StepRunningException) {
            throw new ConflictHttpException('The step is being played: end its run first.');
        } catch (SessionClosedException) {
            throw new ConflictHttpException('The session is over.');
        }

        return $this->views->view($session);
    }
}
