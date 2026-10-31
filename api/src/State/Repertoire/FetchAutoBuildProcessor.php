<?php

declare(strict_types=1);

namespace App\State\Repertoire;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\ApiResource\Repertoire\AutoBuild;
use App\Repertoire\Auto\AutoBuildService;
use App\Repertoire\Lichess\LichessUnavailableException;
use App\Security\AuthenticatedUser;
use App\Security\RateLimit\RateLimitGuard;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * POST /repertoires/auto-builds/{id}/fetch: the build after the next batch of games, with the
 * games kept from it (batch). 404 unknown build; 503 Lichess unavailable (the SPA waits
 * Retry-After and asks again).
 *
 * @implements ProcessorInterface<mixed, AutoBuild>
 */
final class FetchAutoBuildProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly AutoBuildService $builds,
        private readonly AuthenticatedUser $authenticatedUser,
        private readonly RateLimitGuard $rateLimitGuard,
        private readonly RateLimiterFactoryInterface $repertoireAutoFetchLimiter,
        private readonly AutoBuildViews $views,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): AutoBuild
    {
        $user = $this->authenticatedUser->get();
        $this->rateLimitGuard->consume($this->repertoireAutoFetchLimiter, $user->getId()->toRfc4122());
        $id = RepertoireIds::fromUri($uriVariables, 'id');
        $build = null === $id ? null : $this->builds->get($user, $id);
        if (null === $build) {
            throw new NotFoundHttpException();
        }

        try {
            $batch = $this->builds->fetch($build);
        } catch (LichessUnavailableException $e) {
            throw LichessUnavailableHttpException::from($e);
        }

        return $this->views->view($build, $batch);
    }
}
