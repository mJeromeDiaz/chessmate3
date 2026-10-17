<?php

declare(strict_types=1);

namespace App\State\Parcours;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Parcours\LaunchInput;
use App\ApiResource\Training\Session;
use App\Enum\Parcours\Effort;
use App\Enum\Parcours\Theme;
use App\Parcours\ParcoursLauncher;
use App\Parcours\ParcoursUnavailableException;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\State\Training\SessionViewFactory;
use App\Training\Exception\InvalidSessionException;
use App\Training\Exception\SessionInProgressException;
use App\Training\Exception\SubjectNotFoundException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\Uid\Uuid;

/**
 * POST /parcours (docs/PARCOURS.md): launches a parcours, a session of its five tiers; its first
 * tier is started by `POST /training/sessions/{id}/next`. 404: the repertoire is not the user's
 * (or missing for an opening); 409: another session in progress (`session_in_progress`) or the
 * repertoire cannot make a parcours (`no_opening`, `empty_repertoire`).
 *
 * @implements ProcessorInterface<mixed, Session>
 */
final class LaunchProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ParcoursLauncher $launcher,
        private readonly SessionViewFactory $views,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $trainingSessionStartLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Session
    {
        if (!$data instanceof LaunchInput) {
            throw new \LogicException('A parcours input is expected.');
        }
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->trainingSessionStartLimiter, $user->getId()->toRfc4122());

        try {
            $session = $this->launcher->launch(
                $user,
                Theme::from($data->theme),
                Effort::from($data->effort),
                null === $data->repertoireId ? null : Uuid::fromString($data->repertoireId),
            );
        } catch (SubjectNotFoundException) {
            throw new NotFoundHttpException('Repertoire not found.');
        } catch (ParcoursUnavailableException $e) {
            throw new ConflictHttpException($e->reason);
        } catch (SessionInProgressException) {
            throw new ConflictHttpException('session_in_progress');
        } catch (InvalidSessionException $e) {
            throw new UnprocessableEntityHttpException($e->getMessage());
        }

        return $this->views->view($session);
    }
}
