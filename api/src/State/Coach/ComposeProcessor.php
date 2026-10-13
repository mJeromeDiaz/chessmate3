<?php

declare(strict_types=1);

namespace App\State\Coach;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Coach\ComposeInput;
use App\ApiResource\Coach\Composition;
use App\Coach\Session\CoachException;
use App\Coach\Session\Composer;
use App\Repertoire\Auto\AutoBuildService;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use App\State\Repertoire\RepertoireIds;
use App\Training\Exception\PlanLimitException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /coach/builds/{id}/session: composes the tailored session (docs/COACH.md).
 *
 * @implements ProcessorInterface<ComposeInput, Composition>
 */
final class ComposeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AutoBuildService $builds,
        private readonly Composer $composer,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $coachComposeLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Composition
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->coachComposeLimiter, $user->getId()->toRfc4122());
        $id = RepertoireIds::fromUri($uriVariables, 'id');
        $build = null === $id ? null : $this->builds->get($user, $id);
        if (null === $build) {
            throw new NotFoundHttpException();
        }

        try {
            $composition = $this->composer->compose($user, $build, $data->withRepertoire);
        } catch (CoachException $e) {
            throw new ConflictHttpException($e->reason, $e);
        } catch (PlanLimitException $e) {
            throw new UnprocessableEntityHttpException('limit_plans', $e);
        }

        $view = new Composition();
        $view->planId = $composition['planId'];
        $view->minutes = $composition['minutes'];
        $view->diagnosis = $composition['diagnosis'];
        $view->repertoire = $composition['repertoire'];
        $view->modules = $composition['modules'];

        return $view;
    }
}
