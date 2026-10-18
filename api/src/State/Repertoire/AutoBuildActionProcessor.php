<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Repertoire\AutoBuild;
use App\ApiResource\Repertoire\AutoBuildExploreInput;
use App\ApiResource\Repertoire\AutoBuildSelectionInput;
use App\Repertoire\Auto\AutoBuildException;
use App\Repertoire\Auto\AutoBuildService;
use App\Repertoire\Exception\LimitReachedException;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactory;

/**
 * POST /repertoires/auto-builds/{id}/selection, /explore, /evaluate and /apply: the build
 * afterwards. 404 unknown build; 409 not_ready, already_applied; 422 invalid_selection,
 * nothing_selected, unconfirmed_lines, limit_repertoires; 503 Lichess unavailable (explore,
 * evaluate: nothing learnt).
 *
 * @implements ProcessorInterface<AutoBuildSelectionInput|AutoBuildExploreInput|null, AutoBuild>
 */
final class AutoBuildActionProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AutoBuildService $builds,
        private readonly AutoBuildViews $views,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactory $repertoireAutoEditLimiter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AutoBuild
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->repertoireAutoEditLimiter, $user->getId()->toRfc4122());
        $id = RepertoireIds::fromUri($uriVariables, 'id');
        $build = null === $id ? null : $this->builds->get($user, $id);
        if (null === $build) {
            throw new NotFoundHttpException();
        }

        try {
            $name = $operation->getName();
            if ('repertoire_auto_build_select' === $name) {
                $input = $data instanceof AutoBuildSelectionInput ? $data : new AutoBuildSelectionInput();
                $this->builds->select($build, $input->families, $input->choices, $input->removed, $input->kept, $input->suspended);
            } elseif ('repertoire_auto_build_explore' === $name) {
                $input = $data instanceof AutoBuildExploreInput ? $data : new AutoBuildExploreInput();
                $this->builds->explore($build, $input->resume, $input->family, $input->cut);
            } elseif ('repertoire_auto_build_evaluate' === $name) {
                $this->builds->evaluate($build);
            } elseif ('repertoire_auto_build_apply' === $name) {
                $this->builds->apply($build);
            } else {
                throw new \LogicException('Unexpected operation.');
            }
        } catch (AutoBuildException $e) {
            throw \in_array($e->reason, [AutoBuildException::NOT_READY, AutoBuildException::ALREADY_APPLIED], true)
                ? new ConflictHttpException($e->reason, $e)
                : new UnprocessableEntityHttpException($e->reason, $e);
        } catch (LimitReachedException $e) {
            throw new UnprocessableEntityHttpException('limit_'.$e->limit, $e);
        } catch (LichessUnavailableException $e) {
            throw LichessUnavailableHttpException::from($e);
        }

        return $this->views->view($build);
    }
}
