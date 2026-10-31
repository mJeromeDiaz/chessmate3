<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Repertoire\AutoBuild;
use App\ApiResource\Repertoire\CreateAutoBuildInput;
use App\Repertoire\Auto\AutoBuildException;
use App\Repertoire\Auto\AutoBuildService;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * POST /repertoires/auto-builds: 201 "fetching" (POST /fetch until ready). 409 lichess_not_linked;
 * 422 invalid_settings.
 *
 * @implements ProcessorInterface<CreateAutoBuildInput, AutoBuild>
 */
final class CreateAutoBuildProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AutoBuildService $builds,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $repertoireAutoBuildLimiter,
        private readonly AutoBuildViews $views,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AutoBuild
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->repertoireAutoBuildLimiter, $user->getId()->toRfc4122());

        try {
            return $this->views->view($this->builds->create($user, (int) $data->maxGames, $data->perfs));
        } catch (AutoBuildException $e) {
            throw AutoBuildException::LICHESS_NOT_LINKED === $e->reason ? new ConflictHttpException($e->reason, $e) : new UnprocessableEntityHttpException($e->reason, $e);
        }
    }
}
